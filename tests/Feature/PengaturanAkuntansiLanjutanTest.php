<?php

namespace Tests\Feature;

use App\Models\AccountingSetting;
use App\Models\BaganAkun;
use App\Models\BiayaTambahan;
use App\Models\KategoriBahan;
use App\Models\LayerPersediaan;
use App\Models\User;
use App\Services\AccountingReconciliationService;
use App\Services\DocumentNumberService;
use App\Services\LandedCostService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PengaturanAkuntansiLanjutanTest extends TestCase
{
    use DatabaseTransactions;

    public function test_landed_cost_has_a_counter_account_that_does_not_disturb_the_payables_invariant(): void
    {
        $akuntansi = User::where('type', User::ROLE_ACCOUNTING)->firstOrFail();
        Auth::setUser($akuntansi);

        $akunBiaya = AccountingSetting::accountId(AccountingSetting::BIAYA_MASIH_HARUS_DIBAYAR);
        $akunHutang = AccountingSetting::accountId(AccountingSetting::HUTANG_USAHA);

        $this->assertNotSame(
            $akunHutang,
            $akunBiaya,
            'Landed cost tidak boleh mengkredit akun kontrol hutang supplier; itulah yang dulu membuat fitur ini tidak terpakai.'
        );

        $layer = LayerPersediaan::where('stock_status', 'AVAILABLE')->where('remaining_quantity', '>', 0)->firstOrFail();
        $nilaiSebelum = round((float) $layer->unit_cost * (float) $layer->remaining_quantity, 2);

        $biaya = BiayaTambahan::create([
            'number' => app(DocumentNumberService::class)->internal('BTB', 'LC'),
            'date' => today()->toDateString(),
            'description' => 'Uji ongkos angkut',
            'total_amount' => 60000,
            'allocation_basis' => 'VALUE',
            'credit_coa_id' => $akunBiaya,
            'status' => 'DRAFT',
            'created_by' => $akuntansi->id,
        ]);

        $service = app(LandedCostService::class);
        $service->allocate($biaya, [$layer->id]);
        $jurnal = $service->post($biaya);

        $this->assertEqualsWithDelta(60000, (float) $jurnal->total_debit, 0.01);
        $this->assertEqualsWithDelta(
            $nilaiSebelum + 60000,
            round((float) $layer->fresh()->unit_cost * (float) $layer->fresh()->remaining_quantity, 2),
            0.05,
            'Seluruh biaya tambahan harus masuk ke nilai layer.'
        );

        $invarian = app(AccountingReconciliationService::class)->checks();

        foreach (['ap', 'stock', 'journal'] as $kunci) {
            $this->assertSame(
                0,
                (int) $invarian->firstWhere('key', $kunci)['invalid'],
                "Invarian {$kunci} harus tetap valid setelah landed cost diposting."
            );
        }
    }

    public function test_the_accounting_mapping_form_can_be_saved_with_service_categories_present(): void
    {
        $akuntansi = User::where('type', User::ROLE_ACCOUNTING)->firstOrFail();

        $kategoriJasa = KategoriBahan::idKategoriJasa();

        $this->assertNotEmpty($kategoriJasa, 'Data demo harus punya kategori jasa, kalau tidak pengujian ini kosong.');

        $global = [];

        foreach ([
            'HUTANG_USAHA', 'PPN_MASUKAN', 'PPN_IMPOR', 'HUTANG_PPH23', 'HUTANG_PPH22', 'HUTANG_PPH4A2',
            'BIAYA_BANK', 'BEBAN_MATERAI', 'SELISIH_BAYAR', 'BIAYA_ONGKIR', 'DISKON_PEMBELIAN', 'UANG_MUKA_SUPPLIER',
        ] as $kunci) {
            $global[$kunci] = AccountingSetting::where('key', $kunci)->value('coa_id');
        }

        $categories = [];

        foreach (KategoriBahan::all() as $kategori) {
            $categories[$kategori->id] = [
                'coa_persediaan_id' => $kategori->coa_persediaan_id,
                'coa_beban_id' => $kategori->coa_beban_id,
                'coa_clearing_lpb_id' => $kategori->coa_clearing_lpb_id,
                'coa_beban_selisih_opname_id' => $kategori->coa_beban_selisih_opname_id,
                'coa_koreksi_opname_id' => $kategori->coa_koreksi_opname_id,
            ];
        }

        $this->actingAs($akuntansi)
            ->putJson(route('bagan-akun.mapping.update'), [
                'global' => $global,
                'categories' => $categories,
                'alasan' => 'Uji penyimpanan mapping dengan kategori jasa.',
            ])
            ->assertSuccessful();
    }

    public function test_a_goods_category_still_refuses_an_expense_account_as_its_inventory_account(): void
    {
        $akuntansi = User::where('type', User::ROLE_ACCOUNTING)->firstOrFail();

        $kategoriBarang = KategoriBahan::all()
            ->first(fn (KategoriBahan $k) => !in_array($k->id, KategoriBahan::idKategoriJasa(), true));

        $this->assertNotNull($kategoriBarang);

        $akunBeban = BaganAkun::where('kategori_akun', 'BEBAN')->where('posisi_normal', 'DEBIT')
            ->where('is_postable', true)->firstOrFail();

        $categories = [$kategoriBarang->id => [
            'coa_persediaan_id' => $akunBeban->id,
            'coa_beban_id' => $kategoriBarang->coa_beban_id,
            'coa_clearing_lpb_id' => $kategoriBarang->coa_clearing_lpb_id,
            'coa_beban_selisih_opname_id' => $kategoriBarang->coa_beban_selisih_opname_id,
            'coa_koreksi_opname_id' => $kategoriBarang->coa_koreksi_opname_id,
        ]];

        $global = [];

        foreach ([
            'HUTANG_USAHA', 'PPN_MASUKAN', 'PPN_IMPOR', 'HUTANG_PPH23', 'HUTANG_PPH22', 'HUTANG_PPH4A2',
            'BIAYA_BANK', 'BEBAN_MATERAI', 'SELISIH_BAYAR', 'BIAYA_ONGKIR', 'DISKON_PEMBELIAN', 'UANG_MUKA_SUPPLIER',
        ] as $kunci) {
            $global[$kunci] = AccountingSetting::where('key', $kunci)->value('coa_id');
        }

        $this->actingAs($akuntansi)
            ->putJson(route('bagan-akun.mapping.update'), [
                'global' => $global,
                'categories' => $categories,
                'alasan' => 'Uji penolakan akun barang.',
            ])
            ->assertJsonValidationErrors("categories.{$kategoriBarang->id}.coa_persediaan_id");
    }
}
