<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePengirimanSubkontrakRequest;
use App\Http\Requests\TerimaPengirimanSubkontrakRequest;
use App\Models\Bahan;
use App\Models\Gudang;
use App\Models\PengirimanSubkontrak;
use App\Models\Supplier;
use App\Services\SubkontrakService;
use Illuminate\Http\Request;
use RuntimeException;

class SubkontrakController extends Controller
{
    public function __construct(private SubkontrakService $subkontrak) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', PengirimanSubkontrak::class);

        $gudangDiizinkan = array_values($request->user()->accessibleGudangIds('transfer'));

        return view('subkontrak.index', [
            'daftar' => PengirimanSubkontrak::with(['supplier', 'details.bahan', 'gudangAsal'])
                ->whereIn('gudang_asal_id', $gudangDiizinkan)
                ->orderByDesc('id')
                ->paginate(25)
                ->withQueryString(),
            'supplier' => Supplier::orderBy('nama')->get(['id', 'nama']),
            'gudang' => Gudang::whereIn('id', $gudangDiizinkan)
                ->where('jenis', Gudang::NORMAL)
                ->where('kode', '!=', SubkontrakService::KODE_GUDANG)
                ->orderBy('nama')
                ->get(['id', 'nama']),
            'bahan' => Bahan::orderBy('nama')->limit(500)->get(['id', 'nama', 'satuan']),
            'terlambat' => $this->subkontrak->terlambat(),
            'tanpaGudang' => $gudangDiizinkan === [],
        ]);
    }

    public function store(StorePengirimanSubkontrakRequest $request)
    {
        try {
            $pengiriman = $this->subkontrak->kirim($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['subkontrak' => $e->getMessage()]);
        }

        return back()->with('success', "Pengiriman subkontrak {$pengiriman->nomor} dicatat dan stoknya pindah ke Gudang Subkontrak.");
    }

    public function terima(TerimaPengirimanSubkontrakRequest $request, PengirimanSubkontrak $subkontrak)
    {
        $data = $request->validated();

        try {
            $hasil = $this->subkontrak->terima($subkontrak, $data['kembali'], $data['tanggal'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['subkontrak' => $e->getMessage()]);
        }

        return back()->with('success', "Pengembalian {$hasil->nomor} dicatat; stoknya kembali ke gudang asal.");
    }
}
