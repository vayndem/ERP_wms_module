<?php

namespace App\Http\Controllers;

use App\Http\Requests\SelesaikanDataPesananRequest;
use App\Http\Requests\StoreDataPesananRequest;
use App\Models\DataPesanan;
use App\Services\SerapanProduksiService;
use App\Models\PusatKerja;
use App\Models\JamKerjaProduksi;
use App\Http\Requests\StoreJamKerjaProduksiRequest;
use App\Models\Gudang;
use App\Models\PesananPenjualan;
use App\Models\PesananPenjualanDetail;
use App\Services\DataPesananService;
use Illuminate\Http\Request;
use RuntimeException;

class DataPesananController extends Controller
{
    public function __construct(private DataPesananService $produksi) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', DataPesanan::class);

        $status = $request->input('status');

        $pesanan = DataPesanan::with(['pesananPenjualan.pelanggan', 'bahanHasil', 'gudang'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('data_pesanan.index', [
            'pesanan' => $pesanan,
            'status' => $status,
            'totalWip' => $this->produksi->saldoWipSeluruh(),
        ]);
    }

    public function create(Request $request)
    {
        $this->authorize('create', DataPesanan::class);

        return view('data_pesanan.create', [
            'pesananPenjualan' => PesananPenjualan::with(['pelanggan', 'details.bahan'])
                ->where('status', PesananPenjualan::OPEN)
                ->orderByDesc('tanggal')
                ->limit(100)
                ->get(),
            'gudang' => Gudang::orderBy('nama')->get(),
        ]);
    }

    public function store(StoreDataPesananRequest $request)
    {
        $detail = PesananPenjualanDetail::findOrFail($request->validated('pesanan_penjualan_detail_id'));

        try {
            $pesanan = $this->produksi->buat($detail, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['produksi' => $e->getMessage()]);
        }

        return redirect()->route('data-pesanan.show', $pesanan)
            ->with('success', "Perintah kerja {$pesanan->nomor} dibuat.");
    }

    public function show(DataPesanan $pesanan)
    {
        $this->authorize('view', $pesanan);

        $pesanan->load(['pesananPenjualan.pelanggan', 'pesananDetail.bahan', 'bahanHasil', 'gudang', 'biaya', 'pemakaian']);

        return view('data_pesanan.show', [
            'pesanan' => $pesanan,
            'serapan' => app(SerapanProduksiService::class)->ringkasan($pesanan),
            'pusatKerja' => PusatKerja::where('status', PusatKerja::AKTIF)->orderBy('kode')->get(),
        ]);
    }

    public function catatJamKerja(StoreJamKerjaProduksiRequest $request, DataPesanan $pesanan)
    {
        $data = $request->validated();

        try {
            app(SerapanProduksiService::class)->catat(
                $pesanan,
                PusatKerja::findOrFail($data['pusat_kerja_id']),
                $data,
                $request->user()
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['produksi' => $e->getMessage()]);
        }

        return back()->with('success', 'Jam kerja diserap ke barang dalam proses perintah kerja ini.');
    }

    public function hapusJamKerja(DataPesanan $pesanan, JamKerjaProduksi $jamKerja)
    {
        $this->authorize('catatJamKerja', $pesanan);

        if ((int) $jamKerja->data_pesanan_id !== (int) $pesanan->id) {
            abort(404);
        }

        try {
            app(SerapanProduksiService::class)->batalkan($jamKerja);
        } catch (RuntimeException $e) {
            return back()->withErrors(['produksi' => $e->getMessage()]);
        }

        return back()->with('success', 'Serapan jam kerja ditarik kembali dari barang dalam proses.');
    }

    public function rilis(Request $request, DataPesanan $pesanan)
    {
        $this->authorize('rilis', $pesanan);

        try {
            $this->produksi->rilis($pesanan);
        } catch (RuntimeException $e) {
            return back()->withErrors(['produksi' => $e->getMessage()]);
        }

        return back()->with('success', 'Perintah kerja dirilis dan siap menyerap biaya.');
    }

    public function selesaikan(SelesaikanDataPesananRequest $request, DataPesanan $pesanan)
    {
        try {
            $pesanan = $this->produksi->selesaikan($pesanan, (float) $request->validated('jumlah_selesai'), $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['produksi' => $e->getMessage()]);
        }

        return back()->with('success', "Perintah kerja selesai. Harga pokok dikunci pada Rp " . number_format((float) $pesanan->biaya_per_unit, 2, ',', '.') . " per unit.");
    }

    public function batalkan(Request $request, DataPesanan $pesanan)
    {
        $this->authorize('batalkan', $pesanan);

        try {
            $this->produksi->batalkan($pesanan);
        } catch (RuntimeException $e) {
            return back()->withErrors(['produksi' => $e->getMessage()]);
        }

        return back()->with('success', 'Perintah kerja dibatalkan.');
    }
}
