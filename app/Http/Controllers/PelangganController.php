<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePelangganRequest;
use App\Models\Pelanggan;
use App\Services\PlafonKreditService;
use Illuminate\Http\Request;

class PelangganController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Pelanggan::class);

        $cari = trim((string) $request->input('cari'));

        $pelanggan = Pelanggan::query()
            ->when($cari !== '', fn ($query) => $query->where(
                fn ($q) => $q->where('nama', 'like', "%{$cari}%")->orWhere('kode', 'like', "%{$cari}%")
            ))
            ->orderBy('nama')
            ->paginate(25)
            ->withQueryString();

        $plafon = app(PlafonKreditService::class);
        $eksposur = $pelanggan->mapWithKeys(fn (Pelanggan $baris) => [$baris->id => $plafon->eksposur($baris)]);

        return view('pelanggan.index', compact('pelanggan', 'cari', 'eksposur'));
    }

    public function create()
    {
        $this->authorize('create', Pelanggan::class);

        return view('pelanggan.form', ['pelanggan' => new Pelanggan(['termin_hari' => 30, 'is_active' => true])]);
    }

    public function store(StorePelangganRequest $request)
    {
        $pelanggan = Pelanggan::create($request->validated() + ['is_active' => $request->boolean('is_active', true)]);

        return redirect()->route('pelanggan.index')->with('success', "Pelanggan {$pelanggan->nama} tersimpan.");
    }

    public function edit(Pelanggan $pelanggan)
    {
        $this->authorize('update', $pelanggan);

        return view('pelanggan.form', compact('pelanggan'));
    }

    public function update(StorePelangganRequest $request, Pelanggan $pelanggan)
    {
        $pelanggan->update($request->validated() + ['is_active' => $request->boolean('is_active')]);

        return redirect()->route('pelanggan.index')->with('success', "Pelanggan {$pelanggan->nama} diperbarui.");
    }

    public function destroy(Pelanggan $pelanggan)
    {
        $this->authorize('delete', $pelanggan);

        if ($pelanggan->pesanan()->exists()) {
            return back()->withErrors(['pelanggan' => 'Pelanggan yang sudah memiliki pesanan tidak dapat dihapus.']);
        }

        $pelanggan->delete();

        return back()->with('success', 'Pelanggan dihapus.');
    }
}
