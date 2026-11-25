<?php

namespace App\Http\Controllers;

use App\Models\FakturPembelian;
use App\Models\KodeObjekPajak;
use App\Models\PenerimaanBarang;
use App\Models\Jurnal;
use App\Http\Requests\StoreFakturPembelianRequest;
use App\Http\Requests\UpdateFakturPembelianRequest;
use App\Policies\FakturPembelianPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Services\WmsAccountingService;
use App\Models\TaxRate;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\KapitalisasiAsetService;
use App\Services\NotifikasiService;
use App\Services\ThreeWayMatchService;
use App\Exports\GenericTableExport;
use Maatwebsite\Excel\Facades\Excel;

class FakturPembelianController extends Controller
{
    public function __construct(private WmsAccountingService $accounting, private DocumentNumberService $numbers, private ThreeWayMatchService $matching, private NotifikasiService $notifikasi, private KapitalisasiAsetService $kapitalisasi) {}
    public function index(Request $request)
    {
        $this->authorize('viewAny', FakturPembelian::class);

        if ($request->ajax()) {
            $paymentStatus = $request->input('payment_status');
            $query = FakturPembelian::with(['supplier'])
                ->withExists(['payments as memiliki_pembayaran', 'jurnalFaktur as memiliki_jurnal_faktur'])
                ->when(
                    in_array((string) $paymentStatus, [FakturPembelian::PENDING_APPROVAL, FakturPembelian::UNPAID, FakturPembelian::PARTIALLY_PAID, FakturPembelian::PAID], true),
                    fn($query) => $query->where('status', $paymentStatus)
                )
                ->when($request->filled('focus'), fn($query) => $query->whereKey($request->integer('focus')));

            return datatables()->of($query)
                ->filterColumn('supplier_nama', function ($query, $keyword) {
                    $query->whereHas('supplier', function ($supplier) use ($keyword) {
                        $supplier->where('nama', 'like', "%{$keyword}%");
                    });
                })
                ->addIndexColumn()
                ->addColumn('supplier_nama', function ($row) {
                    return $row->supplier->nama ?? '-';
                })
                ->addColumn('can_update', function ($row) use ($request) {
                    return $request->user()->can('update', $row);
                })
                ->addColumn('can_delete', function ($row) use ($request) {
                    return $request->user()->can('delete', $row);
                })
                ->addColumn('can_pay', function ($row) use ($request) {
                    return $request->user()->can('pay', $row);
                })
                ->addColumn('can_approve', function ($row) use ($request) {
                    return $request->user()->can('approve', $row);
                })
                ->make(true);
        }

        $paymentNumber = $request->user()->isFinance()
            ? $this->numbers->preview(DocumentNumberService::FINANCIAL, 'PY')
            : null;
        return view('faktur_pembelian.index', compact('paymentNumber'));
    }

    public function reportPdf(Request $request)
    {
        $this->authorize('viewAny', FakturPembelian::class);

        $filters = collect($request->input('filters', []))->filter(fn($value) => $value !== '');
        $search = trim((string) $request->input('search', ''));
        $query = FakturPembelian::with('supplier')->latest('tanggal');

        if ($search !== '') {
            $query->where(fn($q) => $q->where('no_invoice', 'like', "%{$search}%")
                ->orWhere('tanggal', 'like', "%{$search}%")
                ->orWhere('tgl_deadline_pembayaran', 'like', "%{$search}%")
                ->orWhere('status', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn($supplier) => $supplier->where('nama', 'like', "%{$search}%")));
        }

        foreach (['no_invoice', 'tanggal', 'tgl_deadline_pembayaran', 'grand_total', 'sisa_tagihan'] as $field) {
            if ($filters->has($field)) {
                $query->where($field, 'like', "%{$filters[$field]}%");
            }
        }
        if ($filters->has('status_pembayaran')) {
            $query->where('status', 'like', "%{$filters['status_pembayaran']}%");
        }
        if ($filters->has('supplier_nama')) {
            $query->whereHas('supplier', fn($supplier) => $supplier->where('nama', 'like', "%{$filters['supplier_nama']}%"));
        }

        $rows = $query->limit(5000)->get()->map(fn($row) => [
            'no_invoice' => $row->no_invoice,
            'tanggal' => $row->tanggal,
            'supplier_nama' => $row->supplier->nama ?? '-',
            'tgl_deadline_pembayaran' => $row->tgl_deadline_pembayaran ?: '-',
            'grand_total' => 'Rp ' . number_format($row->grand_total, 0, ',', '.'),
            'sisa_tagihan' => 'Rp ' . number_format($row->sisa_tagihan, 0, ',', '.'),
            'status_pembayaran' => $row->status_pembayaran,
        ]);

        return Pdf::loadView('reports.table-pdf', [
            'title' => 'Daftar Faktur Pembelian',
            'columns' => [
                ['key' => 'no_invoice', 'label' => 'No Invoice', 'align' => 'left'],
                ['key' => 'tanggal', 'label' => 'Tanggal', 'align' => 'left'],
                ['key' => 'supplier_nama', 'label' => 'Supplier', 'align' => 'left'],
                ['key' => 'tgl_deadline_pembayaran', 'label' => 'Deadline', 'align' => 'left'],
                ['key' => 'grand_total', 'label' => 'Grand Total', 'align' => 'right'],
                ['key' => 'sisa_tagihan', 'label' => 'Sisa Tagihan', 'align' => 'right'],
                ['key' => 'status_pembayaran', 'label' => 'Status', 'align' => 'left'],
            ],
            'rows' => $rows,
            'search' => $search,
            'filters' => $filters,
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape')->stream('daftar-invoice-lpb.pdf');
    }

    public function reportExcel(Request $request)
    {
        $this->authorize('viewAny', FakturPembelian::class);

        $filters = collect($request->input('filters', []))->filter(fn($value) => $value !== '');
        $search = trim((string) $request->input('search', ''));
        $query = FakturPembelian::with('supplier')->latest('tanggal');

        if ($search !== '') {
            $query->where(fn($q) => $q->where('no_invoice', 'like', "%{$search}%")
                ->orWhere('tanggal', 'like', "%{$search}%")
                ->orWhere('tgl_deadline_pembayaran', 'like', "%{$search}%")
                ->orWhere('status', 'like', "%{$search}%")
                ->orWhereHas('supplier', fn($supplier) => $supplier->where('nama', 'like', "%{$search}%")));
        }

        foreach (['no_invoice', 'tanggal', 'tgl_deadline_pembayaran', 'grand_total', 'sisa_tagihan'] as $field) {
            if ($filters->has($field)) {
                $query->where($field, 'like', "%{$filters[$field]}%");
            }
        }
        if ($filters->has('status_pembayaran')) {
            $query->where('status', 'like', "%{$filters['status_pembayaran']}%");
        }
        if ($filters->has('supplier_nama')) {
            $query->whereHas('supplier', fn($supplier) => $supplier->where('nama', 'like', "%{$filters['supplier_nama']}%"));
        }

        $rows = $query->limit(5000)->get()->map(fn($row) => [
            'no_invoice' => $row->no_invoice,
            'tanggal' => $row->tanggal,
            'supplier_nama' => $row->supplier->nama ?? '-',
            'tgl_deadline_pembayaran' => $row->tgl_deadline_pembayaran ?: '-',
            'grand_total' => (float) $row->grand_total,
            'sisa_tagihan' => (float) $row->sisa_tagihan,
            'status_pembayaran' => $row->status_pembayaran,
        ]);

        $columns = [
            ['key' => 'no_invoice', 'label' => 'No Invoice'],
            ['key' => 'tanggal', 'label' => 'Tanggal'],
            ['key' => 'supplier_nama', 'label' => 'Supplier'],
            ['key' => 'tgl_deadline_pembayaran', 'label' => 'Deadline'],
            ['key' => 'grand_total', 'label' => 'Grand Total'],
            ['key' => 'sisa_tagihan', 'label' => 'Sisa Tagihan'],
            ['key' => 'status_pembayaran', 'label' => 'Status'],
        ];

        return Excel::download(new GenericTableExport($columns, $rows), 'daftar-faktur-pembelian-' . now()->format('Ymd-His') . '.xlsx');
    }

    public function create()
    {
        $this->authorize('create', FakturPembelian::class);
        $lpbs = PenerimaanBarang::whereNull('no_invoice')->where('status', PenerimaanBarang::POSTED)
            ->with(['pembelian.supplier', 'details', 'serviceDetails'])->orderBy('id_lpb', 'desc')->get();
        $supplierIds = $lpbs->pluck('pembelian.supplier_id')->filter()->unique()->values();
        $suppliers = Supplier::whereIn('id', $supplierIds)->orderBy('nama')->get();
        $kodeObjek = KodeObjekPajak::aktif()->orderBy('kode')->get(['kode', 'jenis_pph', 'deskripsi']);
        return view('faktur_pembelian.create', compact('lpbs', 'suppliers', 'kodeObjek'));
    }

    public function getLpbDetail($id_lpb)
    {
        $this->authorize('create', FakturPembelian::class);
        $lpb = PenerimaanBarang::where('id_lpb', $id_lpb)
            ->whereNull('no_invoice')
            ->where('status', PenerimaanBarang::POSTED)
            ->with(['details.bahan', 'serviceDetails.servicePoDetail.category', 'serviceDetails.allocations', 'pembelian.supplier'])
            ->firstOrFail();

        $subTotal = 0;
        $items = $lpb->details->map(function ($detail) use (&$subTotal) {
            $totalHarga = $detail->jumlah_barang_diterima * ($detail->harga ?? 0);
            $subTotal += $totalHarga;

            return [
                'id'                     => $detail->id,
                'id_bahan'               => $detail->id_bahan,
                'nama_bahan'             => $detail->bahan->nama ?? '-',
                'jumlah_barang_diterima' => $detail->jumlah_barang_diterima,
                'harga'                  => $detail->harga ?? 0,
                'total_harga'            => $totalHarga,
            ];
        });
        foreach ($lpb->serviceDetails as $detail) {
            $subTotal += (float) $detail->amount;
            $items->push([
                'id' => $detail->id,
                'id_bahan' => null,
                'nama_bahan' => '[JASA] ' . $detail->servicePoDetail->description,
                'jumlah_barang_diterima' => '100% selesai',
                'harga' => $detail->amount,
                'total_harga' => $detail->amount,
            ]);
        }

        return response()->json([
            'success'   => true,
            'lpb'       => $lpb,
            'sub_total' => $subTotal,
            'items'     => $items
        ]);
    }

    public function store(StoreFakturPembelianRequest $request)
    {
        $validated = $request->validated();

        $invoice = DB::transaction(function () use ($validated) {
            $lpbs = PenerimaanBarang::whereIn('id', $validated['lpb_ids'])->whereNull('no_invoice')
                ->where('status', PenerimaanBarang::POSTED)
                ->with(['details', 'serviceDetails', 'pembelian'])->lockForUpdate()->get();
            if ($lpbs->count() !== count($validated['lpb_ids'])) {
                throw new \RuntimeException('Salah satu LPB sudah digunakan invoice lain.');
            }
            $supplierIds = $lpbs->pluck('pembelian.supplier_id')->unique();
            if ($supplierIds->count() !== 1 || (int) $supplierIds->first() !== (int) $validated['kode_supplier']) {
                throw new \RuntimeException('Semua LPB dalam satu invoice harus berasal dari supplier yang sama.');
            }
            $subTotal = $lpbs->sum(fn($lpb) => $this->receiptAmount($lpb));

            $ppnPercent = $validated['is_ppn'] ? TaxRate::rateFor('PPN', $validated['tanggal']) : 0;
            $jenisPph = $validated['jenis_pph'] ?? null;
            $pphPercent = $jenisPph ? TaxRate::rateFor($jenisPph, $validated['tanggal']) : 0;
            $ppnNominal = round(($subTotal * $ppnPercent) / 100, 2);

            $grandTotal = ($subTotal + $ppnNominal + $validated['ongkir'] + $validated['ppn_impor']) - $validated['diskon'];

            $createdInvoice = FakturPembelian::create([
                'no_invoice'              => $validated['no_invoice'],
                'kode_supplier'           => $validated['kode_supplier'],
                'tanggal'                 => $validated['tanggal'],
                'tgl_deadline_pembayaran' => $validated['tgl_deadline_pembayaran'] ?? null,
                'sub_total'               => $subTotal,
                'jenis_pajak'             => $validated['is_ppn'] ? 'PPN' : 'NON_PPN',
                'dpp_ppn'                 => $validated['is_ppn'] ? $subTotal : 0,
                'tarif_ppn'               => $ppnPercent,
                'ppn'                     => $ppnNominal,
                'no_faktur_pajak'         => $validated['no_faktur_pajak'] ?? null,
                'tanggal_faktur_pajak'    => $validated['tanggal_faktur_pajak'] ?? null,
                'ppnbm'                   => $validated['ppnbm'] ?? 0,
                'kode_objek_pajak'        => $jenisPph ? ($validated['kode_objek_pajak'] ?? null) : null,
                'jenis_pph'               => $jenisPph,
                'dasar_pph'               => $jenisPph ? $subTotal : 0,
                'tarif_pph'               => $pphPercent,
                'diskon'                  => $validated['diskon'],
                'ongkir'                  => $validated['ongkir'],
                'ppn_impor'               => $validated['ppn_impor'],
                'pph'                     => 0,
                'grand_total'             => $grandTotal,
                'total_pembayaran'        => 0,
                'sisa_tagihan'            => $grandTotal,
                'note'                    => $validated['note'] ?? null,
                'mata_uang_asing'         => $validated['mata_uang_asing'] ?? null,
                'kurs'                    => $validated['kurs'] ?? null,
                'nilai_asing'             => $validated['nilai_asing'] ?? null,
                'status'                  => FakturPembelian::PENDING_APPROVAL,
            ]);

            foreach ($lpbs as $lpb) {
                $createdInvoice->receipts()->create([
                    'lpb_id' => $lpb->id,
                    'amount' => $this->receiptAmount($lpb),
                ]);
                $lpb->update(['no_invoice' => $validated['no_invoice']]);
            }

            $match = $this->matching->evaluate($createdInvoice);
            if ($match['status'] === 'BLOCKED') throw new \RuntimeException('Invoice gagal three-way matching dan diblokir.');

            return $createdInvoice;
        });

        $this->notifikasi->mintaPersetujuan(
            [User::ROLE_ACCOUNTING_MANAGER],
            'Faktur pembelian',
            'Faktur menunggu persetujuan Anda',
            $invoice->no_invoice . ' - Rp ' . number_format((float) $invoice->grand_total, 2, ',', '.'),
            route('faktur-pembelian.index'),
            $invoice->no_invoice,
        );

        return response()->json([
            'success' => true,
            'message' => 'Faktur pembelian berhasil dibuat, menunggu persetujuan Accounting Manager sebelum dicatat ke Jurnal COA.',
            'data'    => $invoice
        ], 201);
    }

    public function show($id)
    {
        $invoice = FakturPembelian::with(['supplier', 'payments.userFinance', 'payments.coaKasBank', 'payments.coaSelisih', 'lpbs.details.bahan'])->findOrFail($id);
        $this->authorize('view', $invoice);

        return response()->json([
            'success' => true,
            'data'    => array_merge($invoice->toArray(), [
                'can_update' => request()->user()->can('update', $invoice),
                'can_delete' => request()->user()->can('delete', $invoice),
                'can_pay' => request()->user()->can('pay', $invoice),
                'can_approve' => request()->user()->can('approve', $invoice),
            ])
        ]);
    }

    public function edit($id)
    {
        $invoice = FakturPembelian::with('lpbs')->findOrFail($id);
        $this->authorize('update', $invoice);

        $lpbs = PenerimaanBarang::where('status', PenerimaanBarang::POSTED)
            ->where(fn($query) => $query->whereNull('no_invoice')->orWhereIn('id', $invoice->lpbs->pluck('id')))
            ->whereHas('pembelian', fn($query) => $query->where('supplier_id', $invoice->kode_supplier))
            ->with(['pembelian.supplier', 'serviceDetails'])->get();
        $kodeObjek = KodeObjekPajak::aktif()->orderBy('kode')->get(['kode', 'jenis_pph', 'deskripsi']);
        return view('faktur_pembelian.edit', compact('invoice', 'lpbs', 'kodeObjek'));
    }

    public function update(UpdateFakturPembelianRequest $request, $id)
    {
        $invoice = FakturPembelian::findOrFail($id);
        $this->authorize('update', $invoice);

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $invoice) {
            if ($invoice->payments()->exists()) {
                throw new \RuntimeException('Invoice yang sudah memiliki pembayaran tidak boleh diubah.');
            }
            $lpbs = PenerimaanBarang::whereIn('id', $validated['lpb_ids'])->where('status', PenerimaanBarang::POSTED)
                ->with(['details', 'serviceDetails', 'pembelian'])->lockForUpdate()->get();
            $supplierIds = $lpbs->pluck('pembelian.supplier_id')->unique();
            if (
                $lpbs->count() !== count($validated['lpb_ids'])
                || $supplierIds->count() !== 1
                || (int) $supplierIds->first() !== (int) $validated['kode_supplier']
            ) {
                throw new \RuntimeException('LPB tidak valid atau berasal dari supplier berbeda.');
            }
            foreach ($invoice->lpbs as $oldLpb) {
                $oldLpb->update(['no_invoice' => null]);
            }
            $subTotal = $lpbs->sum(fn($lpb) => $this->receiptAmount($lpb));
            $ppnPercent = $validated['is_ppn'] ? TaxRate::rateFor('PPN', $validated['tanggal']) : 0;
            $jenisPph = $validated['jenis_pph'] ?? null;
            $pphPercent = $jenisPph ? TaxRate::rateFor($jenisPph, $validated['tanggal']) : 0;
            $ppnNominal = round(($subTotal * $ppnPercent) / 100, 2);

            $grandTotal = ($subTotal + $ppnNominal + $validated['ongkir'] + $validated['ppn_impor']) - $validated['diskon'];
            $sisaTagihan = max(0, $grandTotal - $invoice->total_pembayaran);

            $invoice->update([
                'no_invoice'              => $validated['no_invoice'],
                'kode_supplier'           => $validated['kode_supplier'],
                'tanggal'                 => $validated['tanggal'],
                'tgl_deadline_pembayaran' => $validated['tgl_deadline_pembayaran'] ?? null,
                'sub_total'               => $subTotal,
                'jenis_pajak'             => $validated['is_ppn'] ? 'PPN' : 'NON_PPN',
                'dpp_ppn'                 => $validated['is_ppn'] ? $subTotal : 0,
                'tarif_ppn'               => $ppnPercent,
                'ppn'                     => $ppnNominal,
                'no_faktur_pajak'         => $validated['no_faktur_pajak'] ?? null,
                'tanggal_faktur_pajak'    => $validated['tanggal_faktur_pajak'] ?? null,
                'ppnbm'                   => $validated['ppnbm'] ?? 0,
                'kode_objek_pajak'        => $jenisPph ? ($validated['kode_objek_pajak'] ?? null) : null,
                'jenis_pph'               => $jenisPph,
                'dasar_pph'               => $jenisPph ? $subTotal : 0,
                'tarif_pph'               => $pphPercent,
                'diskon'                  => $validated['diskon'],
                'ongkir'                  => $validated['ongkir'],
                'ppn_impor'               => $validated['ppn_impor'],
                'pph'                     => 0,
                'grand_total'             => $grandTotal,
                'sisa_tagihan'            => $sisaTagihan,
                'status'                  => FakturPembelian::PENDING_APPROVAL,
                'note'                    => $validated['note'] ?? null,
                'mata_uang_asing'         => $validated['mata_uang_asing'] ?? null,
                'kurs'                    => $validated['kurs'] ?? null,
                'nilai_asing'             => $validated['nilai_asing'] ?? null,
            ]);
            $invoice->receipts()->delete();
            foreach ($lpbs as $lpb) {
                $invoice->receipts()->create([
                    'lpb_id' => $lpb->id,
                    'amount' => $this->receiptAmount($lpb)
                ]);
                $lpb->update(['no_invoice' => $validated['no_invoice']]);
            }
            $match = $this->matching->evaluate($invoice);
            if ($match['status'] === 'BLOCKED') throw new \RuntimeException('Invoice gagal three-way matching dan diblokir.');
        });

        return response()->json([
            'success' => true,
            'message' => 'Faktur pembelian berhasil diperbarui, menunggu persetujuan Accounting Manager.'
        ]);
    }

    public function approve($id)
    {
        $invoice = FakturPembelian::findOrFail($id);
        $this->authorize('approve', $invoice);

        DB::transaction(function () use ($invoice) {
            $invoice = FakturPembelian::lockForUpdate()->findOrFail($invoice->id);
            abort_if($invoice->status !== FakturPembelian::PENDING_APPROVAL, 422, 'Invoice ini tidak menunggu persetujuan.');

            $match = $this->matching->evaluate($invoice);
            if ($match['status'] === 'BLOCKED') throw new \RuntimeException('Invoice gagal three-way matching dan diblokir.');

            $invoice->update([
                'status' => FakturPembelian::UNPAID,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);
            $this->accounting->postInvoice($invoice->fresh());
        });

        return response()->json([
            'success' => true,
            'message' => 'Faktur pembelian disetujui dan dicatat ke Jurnal COA (Hutang Usaha).',
        ]);
    }

    public function destroy($id)
    {
        $invoice = FakturPembelian::findOrFail($id);
        $this->authorize('delete', $invoice);

        DB::transaction(function () use ($invoice) {
            if ($invoice->payments()->exists()) {
                throw new \RuntimeException('Invoice yang sudah memiliki pembayaran tidak boleh dihapus.');
            }
            $receipts = PenerimaanBarang::where('no_invoice', $invoice->no_invoice)->get();
            foreach ($receipts as $receipt) {
                $receipt->update(['no_invoice' => null]);
            }

            if ($invoice->status !== FakturPembelian::PENDING_APPROVAL) {
                $this->accounting->reverseAutomaticJournal(
                    'INVOICE_SUPPLIER',
                    $invoice->id,
                    'Pembatalan invoice supplier ' . $invoice->no_invoice
                );

                $this->batalkanKapitalisasi($invoice);
            }
            $invoice->receipts()->delete();
            $invoice->update([
                'voided_by' => auth()->id(),
                'voided_at' => now(),
                'void_reason' => 'Dibatalkan melalui sistem',
                'status' => FakturPembelian::VOID,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Faktur pembelian berhasil dihapus.'
        ]);
    }

    private function syncJurnalInvoice(FakturPembelian $invoice): void
    {
        $this->accounting->postInvoice($invoice);
    }

    private function receiptAmount(PenerimaanBarang $lpb): float
    {
        return (float) $lpb->details->sum(fn($detail) => (float) $detail->jumlah_barang_diterima * (float) $detail->harga)
            + (float) $lpb->serviceDetails->sum('amount');
    }
    private function batalkanKapitalisasi(FakturPembelian $invoice): void
    {
        $invoice->loadMissing('receipts.lpb.serviceDetails.servicePoDetail');

        foreach ($invoice->receipts->flatMap(fn ($receipt) => $receipt->lpb->serviceDetails) as $detail) {
            $this->kapitalisasi->batalkanDariJasa($detail);
        }
    }

}
