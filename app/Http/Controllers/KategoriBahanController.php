<?php

namespace App\Http\Controllers;

use App\Models\KategoriBahan;
use App\Models\TipePembebanan;
use App\Models\BaganAkun;
use App\Http\Requests\StoreKategoriBahanRequest;
use App\Http\Requests\UpdateKategoriBahanRequest;
use Illuminate\Http\Request;

class KategoriBahanController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', KategoriBahan::class);

        if ($request->ajax()) {
            $query = KategoriBahan::with([
                'tipePembebanan',
                'coaPersediaan',
                'coaBeban',
                'coaClearingLpb',
                'coaBebanSelisihOpname',
                'coaKoreksiOpname'
            ]);

            return datatables()->of($query)
                ->addIndexColumn()
                ->addColumn('tipe_pembebanan_nama', function ($row) {
                    return $row->tipePembebanan->nama_tipe ?? '-';
                })
                ->addColumn('coa_persediaan_label', function ($row) {
                    return $row->coaPersediaan
                        ? $row->coaPersediaan->kode_akun . ' - ' . $row->coaPersediaan->nama_akun
                        : '-';
                })
                ->addColumn('coa_beban_label', function ($row) {
                    return $row->coaBeban
                        ? $row->coaBeban->kode_akun . ' - ' . $row->coaBeban->nama_akun
                        : '-';
                })
                ->addColumn('coa_clearing_lpb_label', function ($row) {
                    return $row->coaClearingLpb
                        ? $row->coaClearingLpb->kode_akun . ' - ' . $row->coaClearingLpb->nama_akun
                        : '-';
                })
                ->addColumn('coa_beban_selisih_opname_label', fn($row) => $row->coaBebanSelisihOpname
                    ? $row->coaBebanSelisihOpname->kode_akun . ' - ' . $row->coaBebanSelisihOpname->nama_akun : '-')
                ->addColumn('coa_koreksi_opname_label', fn($row) => $row->coaKoreksiOpname
                    ? $row->coaKoreksiOpname->kode_akun . ' - ' . $row->coaKoreksiOpname->nama_akun : '-')
                ->addColumn('can_update', function ($row) use ($request) {
                    return $request->user()->can('update', $row);
                })
                ->addColumn('can_delete', function ($row) use ($request) {
                    return $request->user()->can('delete', $row);
                })
                ->make(true);
        }

        return view('kategoribahan.index');
    }

    public function create()
    {
        $this->authorize('create', KategoriBahan::class);

        $tipePembebanans = TipePembebanan::all();
        $coas = BaganAkun::where('is_active', true)->where('is_postable', true)->orderBy('kode_akun')->get();

        return view('kategoribahan.create', compact('tipePembebanans', 'coas'));
    }

    public function store(StoreKategoriBahanRequest $request)
    {
        $validated = $request->validated();

        $kategori = KategoriBahan::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Kategori bahan berhasil ditambahkan.',
            'data'    => $kategori
        ], 201);
    }

    public function show($id)
    {
        $kategori = KategoriBahan::with([
            'tipePembebanan',
            'coaPersediaan',
            'coaBeban',
            'coaClearingLpb'
        ])->findOrFail($id);

        $this->authorize('view', $kategori);

        return response()->json([
            'success' => true,
            'data'    => $kategori
        ]);
    }

    public function edit($id)
    {
        $kategori = KategoriBahan::findOrFail($id);
        $this->authorize('update', $kategori);

        $tipePembebanans = TipePembebanan::all();
        $coas = BaganAkun::where('is_active', true)->where('is_postable', true)->orderBy('kode_akun')->get();

        return view('kategoribahan.edit', compact('kategori', 'tipePembebanans', 'coas'));
    }

    public function update(UpdateKategoriBahanRequest $request, $id)
    {
        $kategori = KategoriBahan::findOrFail($id);
        $this->authorize('update', $kategori);

        $validated = $request->validated();
        $kategori->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Kategori bahan berhasil diperbarui.',
            'data'    => $kategori
        ]);
    }

    public function destroy($id)
    {
        $kategori = KategoriBahan::findOrFail($id);
        $this->authorize('delete', $kategori);

        $kategori->delete();

        return response()->json([
            'success' => true,
            'message' => 'Kategori bahan berhasil dihapus.'
        ]);
    }
}
