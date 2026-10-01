<?php

namespace App\Http\Controllers;

use App\Exports\GenericTableExport;
use App\Models\PenerimaanBarang;
use App\Services\LacakPembelianService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class LacakPembelianController extends Controller
{
    public function __construct(private LacakPembelianService $lacak) {}

    public function index(Request $request)
    {
        $this->authorize('viewProcurementAnalytics');

        [$lpb, $data] = $this->resolve($request);

        return view('lacak_pembelian.index', [
            'lpb' => $lpb,
            'data' => $data,
            'pilihan' => $this->pilihan(),
        ]);
    }

    public function pdf(Request $request)
    {
        $this->authorize('viewProcurementAnalytics');

        [$lpb, $data] = $this->resolve($request);
        abort_if(!$lpb, 404);

        return Pdf::loadView('reports.table-pdf', [
            'title' => 'Lacak Pembelian ' . $lpb->id_lpb,
            'columns' => $this->kolom(),
            'rows' => $this->rows($data, true),
            'search' => '',
            'filters' => collect(['LPB' => $lpb->id_lpb, 'Tanggal' => $lpb->tanggal?->format('d-m-Y')]),
            'generatedAt' => now(),
        ])->setPaper('a4', 'landscape')->stream('lacak-pembelian-' . $lpb->id_lpb . '.pdf');
    }

    public function excel(Request $request)
    {
        $this->authorize('viewProcurementAnalytics');

        [$lpb, $data] = $this->resolve($request);
        abort_if(!$lpb, 404);

        return Excel::download(
            new GenericTableExport($this->kolom(), $this->rows($data, false)),
            'lacak-pembelian-' . $lpb->id_lpb . '-' . now()->format('Ymd-His') . '.xlsx'
        );
    }

    private function resolve(Request $request): array
    {
        $kode = $request->input('lpb');

        $lpb = $kode
            ? PenerimaanBarang::where('document_type', 'GOODS')->where('id_lpb', $kode)->first()
            : null;

        return [$lpb, $lpb ? $this->lacak->telusuri($lpb) : null];
    }

    private function pilihan()
    {
        return PenerimaanBarang::where('document_type', 'GOODS')
            ->whereIn('status', [PenerimaanBarang::POSTED, PenerimaanBarang::REVERSED])
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'id_lpb', 'tanggal', 'no_po']);
    }

    private function kolom(): array
    {
        return [
            ['key' => 'bahan', 'label' => 'Bahan', 'align' => 'left'],
            ['key' => 'jumlah', 'label' => 'Jumlah', 'align' => 'right'],
            ['key' => 'nilai_pembelian', 'label' => 'Nilai Pembelian', 'align' => 'right'],
            ['key' => 'biaya_tambahan', 'label' => 'Biaya Tambahan', 'align' => 'right'],
            ['key' => 'nilai_masuk', 'label' => 'Total Masuk', 'align' => 'right'],
            ['key' => 'sisa_stok', 'label' => 'Masih Stok', 'align' => 'right'],
            ['key' => 'beban_npk', 'label' => 'Jadi Beban', 'align' => 'right'],
            ['key' => 'selisih_opname', 'label' => 'Selisih Opname', 'align' => 'right'],
            ['key' => 'terjual', 'label' => 'Terjual', 'align' => 'right'],
            ['key' => 'retur', 'label' => 'Retur', 'align' => 'right'],
            ['key' => 'hilang_transit', 'label' => 'Hilang di Jalan', 'align' => 'right'],
            ['key' => 'tidak_terlacak', 'label' => 'Belum Terlacak', 'align' => 'right'],
        ];
    }

    private function rows(?array $data, bool $formatted)
    {
        if (!$data) {
            return collect();
        }

        $uang = fn ($nilai) => $formatted ? 'Rp ' . number_format($nilai, 0, ',', '.') : (float) $nilai;
        $angka = fn ($nilai) => $formatted ? number_format($nilai, 2, ',', '.') : (float) $nilai;

        $rows = $data['baris']->map(fn ($row) => [
            'bahan' => $row['bahan']?->nama ?? '-',
            'jumlah' => $angka($row['jumlah']),
            'nilai_pembelian' => $uang($row['nilai_pembelian']),
            'biaya_tambahan' => $uang($row['biaya_tambahan']),
            'nilai_masuk' => $uang($row['nilai_masuk']),
            'sisa_stok' => $uang($row['sisa_stok']),
            'beban_npk' => $uang($row['beban_npk']),
            'selisih_opname' => $uang($row['selisih_opname']),
            'terjual' => $uang($row['terjual']),
            'retur' => $uang($row['retur']),
            'hilang_transit' => $uang($row['hilang_transit']),
            'tidak_terlacak' => $uang($row['tidak_terlacak']),
        ]);

        return $rows->push([
            'bahan' => 'TOTAL',
            'jumlah' => '',
            'nilai_pembelian' => $uang($data['total_nilai_pembelian']),
            'biaya_tambahan' => $uang($data['total_biaya_tambahan']),
            'nilai_masuk' => $uang($data['total_nilai_masuk']),
            'sisa_stok' => $uang($data['total_sisa_stok']),
            'beban_npk' => $uang($data['total_beban_npk']),
            'selisih_opname' => $uang($data['total_selisih_opname']),
            'terjual' => $uang($data['total_terjual']),
            'retur' => $uang($data['total_retur']),
            'hilang_transit' => $uang($data['total_hilang_transit']),
            'tidak_terlacak' => $uang($data['total_tidak_terlacak']),
        ])->values();
    }
}
