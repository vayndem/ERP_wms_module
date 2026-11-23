<?php

namespace App\Http\Controllers;

use App\Exports\GenericTableExport;
use App\Http\Requests\BatalkanBuktiPotongRequest;
use App\Http\Requests\ExportBuktiPotongRequest;
use App\Http\Requests\MasaBuktiPotongRequest;
use App\Http\Requests\TerbitkanBuktiPotongRequest;
use App\Models\BuktiPotong;
use App\Services\BuktiPotongExportService;
use App\Services\BuktiPotongService;
use App\Services\KepatuhanPajakService;
use Maatwebsite\Excel\Facades\Excel;
use RuntimeException;

class BuktiPotongController extends Controller
{
    public function __construct(
        private BuktiPotongService $bukti,
        private BuktiPotongExportService $ekspor,
    ) {}

    public function index(MasaBuktiPotongRequest $request, KepatuhanPajakService $kepatuhan)
    {
        $this->authorize('viewAny', BuktiPotong::class);

        $masa = $request->masa();
        $status = $request->statusFilter();
        $hasil = $kepatuhan->periksa($masa);

        return view('bukti_potong.index', [
            'masa' => $masa,
            'status' => $status ?? 'SEMUA',
            'daftar' => $this->bukti->daftar($masa, $status),
            'tieOut' => $this->bukti->tieOut($masa),
            'penghalang' => $this->ekspor->penghalang($masa, $hasil),
            'temuan' => $kepatuhan->temuanBuktiPotongDari($hasil),
            'peringatanXml' => BuktiPotongExportService::PERINGATAN,
            'pilihanStatus' => BuktiPotongExportService::STATUS_PILIHAN,
        ]);
    }

    public function show(BuktiPotong $buktiPotong)
    {
        $this->authorize('view', $buktiPotong);

        $buktiPotong->load(['supplier', 'pembayaran.invoice', 'kodeObjek', 'menggantikan', 'penggantinya', 'pembuat', 'penerbit', 'lampiran.user']);

        return view('bukti_potong.show', [
            'bukti' => $buktiPotong,
            'masalah' => $buktiPotong->isDraft() ? $this->bukti->masalahPenerbitan($buktiPotong) : [],
            'npwpValid' => $this->bukti->npwpPenerimaValid($buktiPotong),
        ]);
    }

    public function sinkron(MasaBuktiPotongRequest $request)
    {
        $this->authorize('create', BuktiPotong::class);

        $masa = $request->masa();
        $hasil = $this->bukti->sinkron($masa, $request->user());

        return redirect()->route('bukti-potong.index', ['masa' => $masa])->with(
            'success',
            "Sinkronisasi selesai: {$hasil['dibuat']} bukti dibuat, {$hasil['diperbarui']} draft diperbarui, {$hasil['dibatalkan']} draft dibatalkan karena pembayaran VOID, {$hasil['ditandai']} bukti terbit ditandai perlu dibatalkan."
        );
    }

    public function terbitkan(TerbitkanBuktiPotongRequest $request, BuktiPotong $buktiPotong)
    {
        $this->authorize('update', $buktiPotong);

        $validated = $request->validated();

        try {
            $this->bukti->terbitkan($buktiPotong, $validated['nomor_bukti_djp'], $validated['tanggal_diterbitkan'] ?? null, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['bukti_potong' => $e->getMessage()])->withInput();
        }

        return redirect()->route('bukti-potong.show', $buktiPotong)->with('success', 'Bukti potong dicatat sebagai DITERBITKAN.');
    }

    public function batalkan(BatalkanBuktiPotongRequest $request, BuktiPotong $buktiPotong)
    {
        $this->authorize('update', $buktiPotong);

        try {
            $this->bukti->batalkan($buktiPotong, $request->validated()['alasan'], $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['bukti_potong' => $e->getMessage()]);
        }

        return redirect()->route('bukti-potong.show', $buktiPotong)->with('success', 'Bukti potong dibatalkan.');
    }

    public function pembetulan(MasaBuktiPotongRequest $request, BuktiPotong $buktiPotong)
    {
        $this->authorize('update', $buktiPotong);

        try {
            $baru = $this->bukti->buatPembetulan($buktiPotong, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['bukti_potong' => $e->getMessage()]);
        }

        return redirect()->route('bukti-potong.show', $baru)->with('success', 'Draft pembetulan dibuat; bukti sebelumnya berstatus DIGANTI.');
    }

    public function xml(ExportBuktiPotongRequest $request)
    {
        $this->authorize('viewAny', BuktiPotong::class);

        $validated = $request->validated();

        try {
            $xml = $this->ekspor->xml($validated['masa'], $validated['status']);
        } catch (RuntimeException $e) {
            return redirect()->route('bukti-potong.index', ['masa' => $validated['masa']])->withErrors(['ekspor' => $e->getMessage()]);
        }

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="bppu-' . $validated['masa'] . '.xml"',
        ]);
    }

    public function excel(ExportBuktiPotongRequest $request)
    {
        $this->authorize('viewAny', BuktiPotong::class);

        $validated = $request->validated();

        return Excel::download(
            new GenericTableExport($this->ekspor->kolomExcel(), $this->ekspor->barisExcel($validated['masa'], $validated['status'])),
            'bukti-potong-' . $validated['masa'] . '.xlsx'
        );
    }
}
