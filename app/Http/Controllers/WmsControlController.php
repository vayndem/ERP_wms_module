<?php

namespace App\Http\Controllers;

use App\Models\Bahan;
use App\Models\AccountingSetting;
use App\Models\BaganAkun;
use App\Models\Gudang;
use App\Models\LayerPersediaan;
use App\Models\LotPersediaan;
use App\Models\ReservasiPersediaan;
use App\Models\FakturPembelian;
use App\Models\BiayaTambahan;
use App\Models\PenerimaanBarang;
use App\Models\PemakaianBarang;
use App\Models\PesananPengambilan;
use App\Models\PemeriksaanKualitas;
use App\Models\SaranPengisianUlang;
use App\Models\LokasiGudang;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WmsControlController extends Controller
{
    private function akunLawanLandedCost(): ?int
    {
        try {
            return AccountingSetting::accountId(AccountingSetting::BIAYA_MASIH_HARUS_DIBAYAR);
        } catch (\RuntimeException) {
            return null;
        }
    }

    public function index(Request $request): View
    {
        $this->authorize('viewWmsControl');

        $user = $request->user();
        $warehouseIds = $user->isProduction() ? $user->accessibleGudangIds() : Gudang::pluck('id')->all();
        $mayControlFinance = $user->can('controlInventoryFinance');
        $mayMatchInvoice = $user->can('matchSupplierInvoice');

        return view('wms_control.index', [
            'locations' => LokasiGudang::with('gudang')->whereIn('gudang_id', $warehouseIds)->orderBy('code')->limit(100)->get(),
            'lots' => LotPersediaan::with(['bahan', 'serials'])
                ->withSum(['layers as sisa_stok' => fn ($query) => $query->whereIn('gudang_id', $warehouseIds)], 'remaining_quantity')
                ->orderByRaw('expires_at IS NULL, expires_at')
                ->limit(100)->get(),
            'lotOptions' => LotPersediaan::with('bahan')->latest()->limit(100)->get(),
            'reservations' => ReservasiPersediaan::with(['gudang', 'bahan'])->whereIn('gudang_id', $warehouseIds)->latest()->limit(100)->get(),
            'picks' => PesananPengambilan::with(['lines', 'npk'])->whereIn('gudang_id', $warehouseIds)->latest()->limit(50)->get(),
            'inspections' => PemeriksaanKualitas::with('lpb')->latest()->limit(50)->get(),
            'suggestions' => SaranPengisianUlang::with(['gudang', 'bahan'])->whereIn('gudang_id', $warehouseIds)->where('status', SaranPengisianUlang::OPEN)->orderByRaw("FIELD(priority, 'CRITICAL', 'HIGH', 'NORMAL')")->limit(100)->get(),
            'landedCosts' => $mayControlFinance ? BiayaTambahan::latest()->limit(50)->get() : collect(),
            'layers' => $mayControlFinance ? LayerPersediaan::with(['bahan', 'gudang'])->where('remaining_quantity', '>', 0)->latest()->limit(200)->get() : collect(),
            'gudangs' => Gudang::whereIn('id', $warehouseIds)->where('aktif', true)->orderBy('nama')->get(),
            'bahans' => Bahan::orderBy('nama')->get(),
            'pendingLpbs' => PenerimaanBarang::with('details.bahan')->whereIn('gudang_id', $warehouseIds)->whereIn('status', [PenerimaanBarang::DRAFT, PenerimaanBarang::POSTED])->where(fn ($query) => $query->whereNull('document_type')->orWhere('document_type', '!=', 'SERVICE_BAP'))->where('receiving_status', '!=', 'PUTAWAY')->latest()->limit(30)->get(),
            'invoices' => $mayMatchInvoice ? FakturPembelian::where('status', '!=', FakturPembelian::VOID)->latest()->limit(30)->get() : collect(),
            'creditAccounts' => $mayControlFinance ? BaganAkun::where('is_active', true)->where('is_postable', true)->whereIn('kategori_akun', ['LIABILITAS', 'ASET'])->orderBy('kode_akun')->get() : collect(),
            'creditAccountDefault' => $mayControlFinance ? $this->akunLawanLandedCost() : null,
            'reversibleLpbs' => $mayControlFinance ? PenerimaanBarang::where('status', PenerimaanBarang::POSTED)->where(fn ($query) => $query->whereNull('document_type')->orWhere('document_type', '!=', 'SERVICE_BAP'))->whereDoesntHave('invoiceReceipts')->latest()->limit(20)->get() : collect(),
            'reversibleNpks' => $mayControlFinance ? PemakaianBarang::where('status', PemakaianBarang::POSTED)->latest()->limit(20)->get() : collect(),
        ]);
    }
}
