<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCrossDockRequest;
use App\Models\CrossDock;
use App\Models\Gudang;
use App\Services\CrossDockService;
use Illuminate\Http\Request;
use RuntimeException;

class CrossDockController extends Controller
{
    public function __construct(private CrossDockService $crossDock) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', CrossDock::class);

        $gudangId = $request->integer('gudang_id') ?: null;
        $gudangDiizinkan = array_values($request->user()->accessibleGudangIds('receive'));

        $daftar = CrossDock::with(['bahan', 'gudang', 'pesananDetail.pesanan.pelanggan', 'penerimaanDetail'])
            ->when($gudangId, fn ($query) => $query->where('gudang_id', $gudangId))
            ->whereIn('gudang_id', $gudangDiizinkan)
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('cross_dock.index', [
            'saran' => $this->crossDock->saran($gudangId, $gudangDiizinkan),
            'daftar' => $daftar,
            'gudang' => Gudang::where('jenis', Gudang::NORMAL)
                ->whereIn('id', $gudangDiizinkan)
                ->orderBy('nama')
                ->get(['id', 'nama']),
            'gudangId' => $gudangId,
            'tanpaGudang' => $gudangDiizinkan === [],
        ]);
    }

    public function store(StoreCrossDockRequest $request)
    {
        try {
            $crossDock = $this->crossDock->tandai($request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['cross_dock' => $e->getMessage()]);
        }

        return back()->with('success', "Cross dock {$crossDock->nomor} dibuat dan stoknya direservasi.");
    }

    public function batalkan(CrossDock $crossDock)
    {
        $this->authorize('batalkan', $crossDock);

        try {
            $this->crossDock->batalkan($crossDock);
        } catch (RuntimeException $e) {
            return back()->withErrors(['cross_dock' => $e->getMessage()]);
        }

        return back()->with('success', "Cross dock {$crossDock->nomor} dibatalkan dan reservasinya dilepas.");
    }
}
