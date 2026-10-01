<?php

namespace App\Http\Controllers;

use App\Http\Requests\SelesaikanBarangTitipanRequest;
use App\Http\Requests\StoreBarangTitipanRequest;
use App\Http\Requests\StorePengeluaranBarangRequest;
use App\Http\Requests\TerimaPengeluaranBarangRequest;
use App\Models\Aset;
use App\Models\BarangTitipan;
use App\Models\PengeluaranBarang;
use App\Models\Supplier;
use App\Services\PengeluaranBarangService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class PengeluaranBarangController extends Controller
{
    public function __construct(private PengeluaranBarangService $service) {}

    private function assertBoleh(): void
    {
        abort_unless(Gate::allows('operateWarehouse') || Gate::allows('viewWmsControl'), 403);
    }

    public function index()
    {
        $this->assertBoleh();

        return view('pengeluaran_barang.index', [
            'pengeluaran' => PengeluaranBarang::with('supplier', 'details.aset', 'petugas')
                ->latest('id')->limit(30)->get(),
            'titipan' => BarangTitipan::with('supplier', 'pengeluaran')->latest('id')->limit(30)->get(),
            'suppliers' => Supplier::orderBy('nama')->get(),
            'asets' => Aset::where('status', Aset::ACTIVE)->orderBy('name')->limit(300)->get(),
            'keperluan' => PengeluaranBarang::KEPERLUAN,
            'terlambat' => $this->service->terlambat(),
        ]);
    }

    public function store(StorePengeluaranBarangRequest $request)
    {
        $pengeluaran = $this->service->buat($request->validated());

        return redirect()->route('pengeluaran-barang.index')
            ->with('success', "Gate pass {$pengeluaran->nomor} dibuat. Kirim ke vendor untuk mengunci catatannya.");
    }

    public function kirim(PengeluaranBarang $pengeluaran)
    {
        $this->assertBoleh();

        try {
            $this->service->kirim($pengeluaran);
        } catch (RuntimeException $e) {
            return back()->withErrors(['pengeluaran' => $e->getMessage()]);
        }

        return back()->with('success', "Gate pass {$pengeluaran->nomor} tercatat keluar ke vendor.");
    }

    public function terima(TerimaPengeluaranBarangRequest $request, PengeluaranBarang $pengeluaran)
    {
        $data = $request->validated();

        try {
            $this->service->terimaKembali(
                $pengeluaran,
                (array) $data['baris'],
                $data['tanggal_kembali'],
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['pengeluaran' => $e->getMessage()]);
        }

        return back()->with('success', "Penerimaan kembali {$pengeluaran->nomor} dicatat.");
    }

    public function destroy(PengeluaranBarang $pengeluaran)
    {
        $this->assertBoleh();

        try {
            $this->service->batalkan($pengeluaran);
        } catch (RuntimeException $e) {
            return back()->withErrors(['pengeluaran' => $e->getMessage()]);
        }

        return back()->with('success', "Gate pass {$pengeluaran->nomor} dibatalkan.");
    }

    public function storeTitipan(StoreBarangTitipanRequest $request)
    {
        $titipan = $this->service->terimaTitipan($request->validated());

        return redirect()->route('pengeluaran-barang.index')
            ->with('success', "Barang titipan {$titipan->nomor} dicatat. Barang ini milik vendor dan tidak masuk persediaan maupun aset.");
    }

    public function selesaikanTitipan(SelesaikanBarangTitipanRequest $request, BarangTitipan $titipan)
    {
        $data = $request->validated();

        try {
            $this->service->selesaikanTitipan(
                $titipan,
                (string) $data['status'],
                $data['tanggal_kembali'] ?? today()->toDateString(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['titipan' => $e->getMessage()]);
        }

        return back()->with('success', "Barang titipan {$titipan->nomor} ditutup sebagai {$titipan->status}.");
    }
}
