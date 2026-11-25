<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReturPembelianRequest;
use App\Models\FakturPembelian;
use App\Models\LayerPersediaan;
use App\Models\PenerimaanBarang;
use App\Models\PenerimaanBarangDetail;
use App\Models\ReturPembelian;
use App\Services\AccountingPeriodService;
use App\Services\DocumentNumberService;
use App\Services\StokGudangService;
use App\Services\WmsAccountingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReturPembelianController extends Controller
{
    public function __construct(
        private WmsAccountingService $accounting,
        private StokGudangService $stokGudang,
        private AccountingPeriodService $periods,
        private DocumentNumberService $numbers
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', ReturPembelian::class);

        if ($request->ajax()) {
            $query = ReturPembelian::with('lpb');

            return datatables()->of($query)
                ->addColumn('id_lpb', fn($row) => $row->lpb->id_lpb ?? '-')
                ->addColumn('status_label', fn($row) => $row->status === ReturPembelian::POSTED ? 'Aktif' : 'Dibalik')
                ->make(true);
        }

        return view('retur_pembelian.index');
    }

    public function create()
    {
        $this->authorize('create', ReturPembelian::class);

        $lpbs = PenerimaanBarang::where('status', PenerimaanBarang::POSTED)
            ->where('document_type', '!=', 'SERVICE_BAP')
            ->with(['pembelian.supplier'])
            ->orderBy('id_lpb', 'desc')
            ->get()
            ->reject(function (PenerimaanBarang $lpb) {
                $invoice = $lpb->no_invoice ? FakturPembelian::where('no_invoice', $lpb->no_invoice)->first() : null;
                return $invoice && in_array($invoice->status, [FakturPembelian::VOID, FakturPembelian::PENDING_APPROVAL], true);
            })
            ->values();
        $documentNumber = $this->numbers->preview(DocumentNumberService::EXTERNAL, 'RTV');

        return view('retur_pembelian.create', compact('lpbs', 'documentNumber'));
    }

    public function getLpbDetail(string $id_lpb)
    {
        $this->authorize('create', ReturPembelian::class);

        $lpb = PenerimaanBarang::where('id_lpb', $id_lpb)
            ->where('status', PenerimaanBarang::POSTED)
            ->with(['details.bahan', 'pembelian.supplier'])
            ->firstOrFail();

        $invoice = $lpb->no_invoice ? FakturPembelian::where('no_invoice', $lpb->no_invoice)->first() : null;
        abort_if($invoice && $invoice->status === FakturPembelian::VOID, 422, 'Invoice terkait LPB ini sudah dibatalkan.');
        abort_if($invoice && $invoice->status === FakturPembelian::PENDING_APPROVAL, 422, 'Invoice terkait LPB ini masih menunggu persetujuan Accounting Manager.');

        $items = $lpb->details->map(function (PenerimaanBarangDetail $detail) {
            return [
                'id' => $detail->id,
                'nama_bahan' => $detail->bahan->nama ?? '-',
                'jumlah_barang_diterima' => (float) $detail->jumlah_barang_diterima,
                'jumlah_tersedia_retur' => max(0, (float) $detail->jumlah_tersisa),
                'harga' => (float) $detail->harga,
            ];
        })->filter(fn($item) => $item['jumlah_tersedia_retur'] > 0.000001)->values();

        return response()->json([
            'success' => true,
            'lpb' => [
                'id' => $lpb->id,
                'id_lpb' => $lpb->id_lpb,
                'supplier' => $lpb->pembelian->supplier->nama ?? '-',
                'no_invoice' => $lpb->no_invoice,
                'invoice_status' => $invoice?->status,
                'invoice_sisa_tagihan' => $invoice ? (float) $invoice->sisa_tagihan : null,
            ],
            'items' => $items,
        ]);
    }

    public function store(StoreReturPembelianRequest $request)
    {
        $validated = $request->validated();
        $user = $request->user();

        $retur = DB::transaction(function () use ($validated, $user) {
            $lpb = PenerimaanBarang::lockForUpdate()->findOrFail($validated['lpb_id']);
            abort_if($lpb->status !== PenerimaanBarang::POSTED, 422, 'LPB harus berstatus posted.');
            abort_if($lpb->document_type === 'SERVICE_BAP', 422, 'Retur pembelian hanya berlaku untuk penerimaan barang, bukan jasa.');
            $invoice = $lpb->no_invoice ? FakturPembelian::where('no_invoice', $lpb->no_invoice)->first() : null;
            abort_if($invoice && $invoice->status === FakturPembelian::VOID, 422, 'Invoice terkait LPB ini sudah dibatalkan.');
            abort_if($invoice && $invoice->status === FakturPembelian::PENDING_APPROVAL, 422, 'Invoice terkait LPB ini masih menunggu persetujuan Accounting Manager.');
            $this->periods->assertOpen($validated['tanggal'], 'Retur pembelian');

            $retur = ReturPembelian::create([
                'no_retur' => $this->numbers->external('RTV'),
                'lpb_id' => $lpb->id,
                'tanggal' => $validated['tanggal'],
                'alasan' => $validated['alasan'],
                'status' => ReturPembelian::POSTED,
                'total_nilai' => 0,
                'created_by' => $user->id,
                'posted_at' => now(),
            ]);

            $totalNilai = 0;
            $detailRows = [];
            foreach ($validated['details'] as $line) {
                $lpbDetail = PenerimaanBarangDetail::lockForUpdate()->findOrFail($line['lpb_detail_id']);
                abort_if($lpbDetail->id_lpb !== $lpb->id_lpb, 422, 'Baris LPB yang dipilih tidak sesuai dengan LPB ini.');

                $layer = LayerPersediaan::where('source_type', 'LPB_DETAIL')->where('source_id', $lpbDetail->id)->lockForUpdate()->firstOrFail();
                $jumlahRetur = (float) $line['jumlah_retur'];
                abort_if(
                    $jumlahRetur > (float) $layer->remaining_quantity + 0.000001,
                    422,
                    "Jumlah retur untuk salah satu barang melebihi stok yang masih tersedia dari LPB ini."
                );

                $layer->update(['remaining_quantity' => (float) $layer->remaining_quantity - $jumlahRetur]);
                $sisaBaru = max(0, (float) $lpbDetail->jumlah_tersisa - $jumlahRetur);
                $lpbDetail->update([
                    'jumlah_retur' => (float) $lpbDetail->jumlah_retur + $jumlahRetur,
                    'jumlah_tersisa' => $sisaBaru,
                    'flag_dipakai' => $sisaBaru > 0 ? 1 : 0,
                ]);

                $harga = (float) $layer->unit_cost;
                $totalHarga = round($jumlahRetur * $harga, 2);
                $totalNilai += $totalHarga;

                $this->stokGudang->keluar(
                    (int) $lpb->gudang_id,
                    (int) $lpbDetail->id_bahan,
                    $jumlahRetur,
                    $harga,
                    'RETUR_PEMBELIAN',
                    'RETUR_PEMBELIAN',
                    $retur->id,
                    "Retur pembelian {$retur->no_retur}"
                );

                $detailRows[] = [
                    'lpb_detail_id' => $lpbDetail->id,
                    'jumlah_retur' => $jumlahRetur,
                    'harga' => $harga,
                    'total_harga' => $totalHarga,
                ];
            }

            $retur->details()->createMany($detailRows);
            $retur->update(['total_nilai' => $totalNilai]);

            $this->accounting->postReturPembelian($retur->fresh('details'));

            return $retur;
        });

        return response()->json([
            'success' => true,
            'message' => 'Retur pembelian berhasil dicatat, mengurangi stok dan GRNI.',
            'data' => $retur,
            'next_document_number' => $this->numbers->preview(DocumentNumberService::EXTERNAL, 'RTV'),
        ], 201);
    }

    public function show(ReturPembelian $returPembelian)
    {
        $this->authorize('view', $returPembelian);
        $returPembelian->load(['lpb.pembelian.supplier', 'details.lpbDetail.bahan', 'creator']);

        return response()->json(['success' => true, 'data' => $returPembelian]);
    }
}
