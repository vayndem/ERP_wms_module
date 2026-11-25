<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreKategoriAsetRequest;
use App\Models\KategoriAset;
use App\Models\BaganAkun;

class KategoriAsetController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', KategoriAset::class);
        $categories = KategoriAset::with(['assetAccount', 'accumulatedAccount', 'expenseAccount'])->orderBy('code')->get();
        $accounts = BaganAkun::where('is_active', true)->where('is_postable', true)->orderBy('kode_akun')->get();
        return view('kategori_aset.index', compact('categories', 'accounts'));
    }
    public function store(StoreKategoriAsetRequest $request)
    {
        KategoriAset::create($request->validated() + ['is_active' => $request->boolean('is_active', true)]);
        return back()->with('success', 'Kategori aset berhasil dibuat.');
    }
    public function update(StoreKategoriAsetRequest $request, KategoriAset $kategoriAset)
    {
        $kategoriAset->update($request->validated() + ['is_active' => $request->boolean('is_active')]);
        return back()->with('success', 'Kategori aset berhasil diperbarui.');
    }
    public function destroy(KategoriAset $kategoriAset)
    {
        $this->authorize('delete', $kategoriAset);
        $kategoriAset->delete();
        return back()->with('success', 'Kategori aset berhasil dihapus.');
    }
}
