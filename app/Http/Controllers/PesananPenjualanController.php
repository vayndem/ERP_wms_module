<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePesananPenjualanRequest;
use App\Http\Requests\StoreSuratJalanRequest;
use App\Models\Bahan;
use App\Models\Gudang;
use App\Models\Pelanggan;
use App\Models\PesananPenjualan;
use App\Models\User;
use App\Services\PenjualanService;
use Illuminate\Http\Request;
use RuntimeException;

class PesananPenjualanController extends Controller
{
    public function __construct(private PenjualanService $penjualan) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PesananPenjualan::class);

        $status = $request->input('status');

        $pesanan = PesananPenjualan::with(['pelanggan', 'gudang', 'sales'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('pesanan_penjualan.index', compact('pesanan', 'status'));
    }

    public function create()
    {
        $this->authorize('create', PesananPenjualan::class);

        return view('pesanan_penjualan.create', [
            'pelanggan' => Pelanggan::aktif()->orderBy('nama')->get(),
            'gudang' => Gudang::where('jenis', Gudang::NORMAL)->orderBy('nama')->get(),
            'bahan' => Bahan::orderBy('nama')->limit(500)->get(['id', 'nama', 'satuan']),
            'sales' => User::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StorePesananPenjualanRequest $request)
    {
        try {
            $pesanan = $this->penjualan->buatPesanan($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['pesanan' => $e->getMessage()]);
        }

        return redirect()->route('pesanan-penjualan.show', $pesanan)
            ->with('success', "Pesanan penjualan {$pesanan->nomor} dibuat.");
    }

    public function show(PesananPenjualan $pesanan)
    {
        $this->authorize('view', $pesanan);

        $pesanan->load(['pelanggan', 'gudang', 'sales', 'details.bahan', 'suratJalan']);

        return view('pesanan_penjualan.show', compact('pesanan'));
    }

    public function kirim(StoreSuratJalanRequest $request, PesananPenjualan $pesanan)
    {
        try {
            $suratJalan = $this->penjualan->buatSuratJalan($pesanan, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['pesanan' => $e->getMessage()]);
        }

        return redirect()->route('surat-jalan.show', $suratJalan)
            ->with('success', "Surat jalan {$suratJalan->nomor} dibuat sebagai draft.");
    }
}
