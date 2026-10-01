<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePenerimaanPembayaranRequest;
use App\Http\Requests\PakaiUangMukaPelangganRequest;
use App\Models\BaganAkun;
use App\Models\UangMukaPelanggan;
use App\Models\FakturPenjualan;
use App\Services\FakturPenjualanService;
use Illuminate\Http\Request;
use RuntimeException;

class FakturPenjualanController extends Controller
{
    public function __construct(private FakturPenjualanService $faktur) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', FakturPenjualan::class);

        $status = $request->input('status');

        $daftar = FakturPenjualan::with('pelanggan')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('faktur_penjualan.index', [
            'faktur' => $daftar,
            'status' => $status,
            'totalPiutang' => round((float) FakturPenjualan::whereIn('status', [
                FakturPenjualan::POSTED,
                FakturPenjualan::PARTIALLY_PAID,
            ])->sum('sisa_tagihan'), 2),
        ]);
    }

    public function show(FakturPenjualan $faktur)
    {
        $this->authorize('view', $faktur);

        $faktur->load(['pelanggan', 'details.bahan', 'pembayaran.kasBank']);

        return view('faktur_penjualan.show', [
            'faktur' => $faktur,
            'kasBank' => BaganAkun::where('is_cash_bank', true)->where('is_active', true)->orderBy('kode_akun')->get(),
            'uangMuka' => UangMukaPelanggan::where('pelanggan_id', $faktur->pelanggan_id)
                ->where('status', UangMukaPelanggan::AKTIF)
                ->orderBy('tanggal')
                ->get(),
        ]);
    }

    public function pakaiUangMuka(PakaiUangMukaPelangganRequest $request, FakturPenjualan $faktur)
    {
        $data = $request->validated();

        try {
            $this->faktur->gunakanUangMuka(
                $faktur,
                UangMukaPelanggan::findOrFail($data['uang_muka_id']),
                (float) $data['jumlah'],
                $data['tanggal'],
                $request->user()
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['faktur' => $e->getMessage()]);
        }

        return back()->with('success', 'Uang muka pelanggan dipakai untuk melunasi faktur ini.');
    }

    public function post(Request $request, FakturPenjualan $faktur)
    {
        $this->authorize('post', $faktur);

        try {
            $this->faktur->posting($faktur, $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['faktur' => $e->getMessage()]);
        }

        return back()->with('success', 'Faktur penjualan diposting: piutang dan PPN keluaran terbentuk.');
    }

    public function destroy(FakturPenjualan $faktur)
    {
        $this->authorize('delete', $faktur);

        $nomor = $faktur->nomor;
        $faktur->details()->delete();
        $faktur->delete();

        return redirect()->route('faktur-penjualan.index')
            ->with('success', "Faktur draft {$nomor} dihapus. Surat jalannya bisa difakturkan ulang.");
    }

    public function bayar(StorePenerimaanPembayaranRequest $request, FakturPenjualan $faktur)
    {
        try {
            $pembayaran = $this->faktur->terimaPembayaran($faktur, $request->validated(), $request->user());
        } catch (RuntimeException $e) {
            return back()->withErrors(['faktur' => $e->getMessage()]);
        }

        return back()->with('success', "Penerimaan {$pembayaran->nomor} tercatat dan piutang berkurang.");
    }
}
