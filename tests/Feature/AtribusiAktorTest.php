<?php

namespace Tests\Feature;

use App\Models\Aset;
use App\Models\Bahan;
use App\Models\FakturPembelian;
use App\Models\Gudang;
use App\Models\LayerPersediaan;
use App\Models\MutasiStok;
use App\Models\PenyusutanAset;
use App\Models\Pelanggan;
use App\Models\PelepasanAset;
use App\Models\PemakaianBarang;
use App\Models\PembayaranFaktur;
use App\Models\PenerimaanBarang;
use App\Models\ReturPembelian;
use App\Models\TransferGudang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AsetAccountingService;
use App\Services\InventoryReversalService;
use App\Services\PlafonKreditService;
use App\Services\StokGudangService;
use App\Services\TransferGudangService;
use App\Services\ThreeWayMatchService;
use App\Services\WarehouseExecutionService;
use App\Services\WmsAccountingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use ReflectionMethod;
use Tests\TestCase;

class AtribusiAktorTest extends TestCase
{
    use DatabaseTransactions;

    public function test_id_aktor_eksplisit_dipakai_tanpa_sesi_autentikasi(): void
    {
        $aktor = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        Auth::logout();

        [$gudang, $bahan] = $this->stokUji($aktor->id, 'EKSPLISIT');
        $mutasi = MutasiStok::where('jenis_referensi', 'ATRIBUSI_EKSPLISIT')->latest('id')->firstOrFail();
        $this->assertSame($aktor->id, (int) $mutasi->user_id);

        $eksekusi = app(WarehouseExecutionService::class);
        $reservasi = $eksekusi->reserve($gudang->id, $bahan->id, 1, userId: $aktor->id);
        $this->assertSame($aktor->id, (int) $reservasi->created_by);
        $this->assertSame($aktor->id, (int) $eksekusi->createPick($reservasi, $aktor->id)->created_by);

        $faktur = FakturPembelian::firstOrFail();
        app(ThreeWayMatchService::class)->evaluate($faktur, userId: $aktor->id);
        $this->assertSame($aktor->id, (int) $faktur->fresh()->matched_by);
    }

    public function test_id_aktor_null_fallback_ke_user_terautentikasi(): void
    {
        $aktor = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        Auth::login($aktor);

        [$gudang, $bahan] = $this->stokUji(null, 'FALLBACK');
        $mutasi = MutasiStok::where('jenis_referensi', 'ATRIBUSI_FALLBACK')->latest('id')->firstOrFail();
        $this->assertSame($aktor->id, (int) $mutasi->user_id);

        $reservasi = app(WarehouseExecutionService::class)->reserve($gudang->id, $bahan->id, 1, userId: null);
        $this->assertSame($aktor->id, (int) $reservasi->created_by);

        $faktur = FakturPembelian::firstOrFail();
        app(ThreeWayMatchService::class)->evaluate($faktur, userId: null);
        $this->assertSame($aktor->id, (int) $faktur->fresh()->matched_by);
    }

    public function test_reversal_mencatat_aktor_eksplisit_tanpa_sesi(): void
    {
        $aktor = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $npk = PemakaianBarang::where('status', PemakaianBarang::POSTED)->whereNull('data_pesanan_id')->firstOrFail();
        Auth::logout();

        $pembalikan = app(InventoryReversalService::class)->reverseNpk($npk, 'Uji aktor eksplisit', $aktor->id);
        $this->assertSame($aktor->id, (int) $pembalikan->created_by);
        $this->assertSame($aktor->id, (int) MutasiStok::where('jenis_mutasi', 'REVERSAL_NPK')->latest('id')->value('user_id'));
    }

    public function test_reversal_dengan_id_null_fallback_ke_user_terautentikasi(): void
    {
        $aktor = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        Auth::login($aktor);
        $npk = PemakaianBarang::where('status', PemakaianBarang::POSTED)->whereNull('data_pesanan_id')->firstOrFail();
        $pembalikan = app(InventoryReversalService::class)->reverseNpk($npk, 'Uji fallback aktor', null);
        $this->assertSame($aktor->id, (int) $pembalikan->created_by);
    }

    public function test_aktor_eksplisit_dipakai_untuk_uang_muka_retur_dan_tukar_tambah(): void
    {
        $aktor = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        Auth::logout();

        $invoice = FakturPembelian::create([
            'no_invoice' => 'ATR-INV-'.$aktor->id,
            'kode_supplier' => Supplier::firstOrFail()->id,
            'tanggal' => today(),
            'grand_total' => 100,
            'sisa_tagihan' => 0,
            'status' => FakturPembelian::PAID,
        ]);
        $retur = ReturPembelian::create([
            'no_retur' => 'ATR-RTV-'.$aktor->id,
            'lpb_id' => PenerimaanBarang::firstOrFail()->id,
            'tanggal' => today(),
            'alasan' => 'Uji atribusi aktor',
            'status' => ReturPembelian::POSTED,
            'total_nilai' => 100,
        ]);
        $method = new ReflectionMethod(WmsAccountingService::class, 'applyReturToInvoice');
        $method->invoke(app(WmsAccountingService::class), $retur, $invoice, 0, 100, $aktor->id);
        $this->assertSame($aktor->id, (int) PembayaranFaktur::findOrFail($retur->fresh()->advance_payment_id)->finance_user_id);

        $aset = Aset::where('status', Aset::ACTIVE)->whereHas('category')->firstOrFail();
        $supplier = Supplier::firstOrFail();
        $pelepasan = app(AsetAccountingService::class)->dispose($aset, [
            'disposal_date' => today()->toDateString(),
            'disposal_type' => PelepasanAset::TRADE_IN,
            'proceeds' => 100000,
            'ppn_keluaran' => 11000,
            'supplier_id' => $supplier->id,
            'reason' => 'Uji aktor eksplisit',
        ], $aktor->id);
        $this->assertSame($aktor->id, (int) $pelepasan->disposed_by);
        $this->assertSame($aktor->id, (int) $pelepasan->uangMuka->finance_user_id);
    }

    public function test_penyusutan_aset_mencatat_aktor_eksplisit_tanpa_sesi(): void
    {
        $aktor = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $aset = Aset::where('status', Aset::ACTIVE)->whereHas('category')->where('residual_value', '>=', 0)->get()
            ->first(fn (Aset $item) => $item->suggestedMonthlyDepreciation() > 0 && (float) $item->book_value - (float) $item->residual_value > $item->suggestedMonthlyDepreciation());
        $this->assertNotNull($aset);
        Auth::logout();

        $penyusutan = app(AsetAccountingService::class)->depreciate($aset, [
            'posting_date' => today()->toDateString(),
            'period_label' => 'ATR-'.$aktor->id,
            'reason' => 'Uji aktor eksplisit',
        ], $aktor->id);

        $this->assertSame($aktor->id, (int) $penyusutan->posted_by);
        $this->assertSame($aktor->id, (int) $penyusutan->journal->created_by);
        $this->assertSame($aktor->id, (int) $penyusutan->journal->posted_by);
    }

    public function test_penerimaan_transfer_mencatat_aktor_eksplisit_tanpa_sesi(): void
    {
        $aktor = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $transfer = TransferGudang::where('status', TransferGudang::DIKIRIM)->first();

        $this->assertNotNull($transfer, 'Data demo harus memiliki transfer yang sedang dikirim.');
        Auth::logout();
        $hasil = app(TransferGudangService::class)->terima($transfer, [], null, [], $aktor->id);

        $this->assertSame($aktor->id, (int) $hasil->diterima_oleh);
        $this->assertSame($aktor->id, (int) $hasil->dikonfirmasi_oleh);
        $this->assertSame($aktor->id, (int) MutasiStok::where('referensi_id', $transfer->id)->whereIn('jenis_mutasi', ['TRANSFER_MASUK', 'CONSIDER_MASUK'])->latest('id')->value('user_id'));
    }

    public function test_plafon_kredit_memakai_gate_dan_super_admin_tetap_lolos(): void
    {
        Gate::define('tembusPlafonKredit', fn (User $user) => $user->isFinance());
        $pelanggan = Pelanggan::create([
            'kode' => 'ATR-PLF',
            'nama' => 'Pelanggan Uji Atribusi',
            'termin_hari' => 30,
            'plafon_kredit' => 1,
            'is_active' => true,
        ]);
        $service = app(PlafonKreditService::class);
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);

        $this->assertTrue($service->periksa($pelanggan, 100, $finance, 'Disetujui melalui gate')['dilampaui']);
        $this->assertTrue($service->periksa($pelanggan, 100, $admin, 'Disetujui Super Admin')['dilampaui']);
    }

    private function stokUji(?int $userId, string $suffix): array
    {
        $gudang = Gudang::where('jenis', Gudang::NORMAL)->firstOrFail();
        $bahan = Bahan::firstOrFail();
        app(StokGudangService::class)->masuk(
            $gudang->id,
            $bahan->id,
            2,
            100,
            'ATRIBUSI_'.$suffix,
            'ATRIBUSI_'.$suffix,
            1,
            userId: $userId,
        );
        LayerPersediaan::create([
            'bahan_id' => $bahan->id,
            'gudang_id' => $gudang->id,
            'stock_status' => 'AVAILABLE',
            'source_type' => 'ATRIBUSI_'.$suffix,
            'source_id' => 1,
            'transaction_date' => today(),
            'initial_quantity' => 2,
            'remaining_quantity' => 2,
            'unit_cost' => 100,
        ]);

        return [$gudang, $bahan];
    }
}
