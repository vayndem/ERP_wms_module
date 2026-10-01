<?php

namespace Tests\Feature;

use App\Models\PemakaianBarang;
use App\Models\PenerimaanBarang;
use App\Models\User;
use App\Services\InventoryReversalService;
use App\Services\LacakPembelianService;
use App\Services\SupplierScorecardService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class ProcurementAnalyticsTest extends TestCase
{
    use DatabaseTransactions;

    private function lpbDenganPemakaian(): PenerimaanBarang
    {
        $npk = PemakaianBarang::where('status', PemakaianBarang::POSTED)->firstOrFail();

        $lpb = PenerimaanBarang::where('document_type', 'GOODS')
            ->where('status', PenerimaanBarang::POSTED)
            ->get()
            ->first(fn (PenerimaanBarang $kandidat) => app(LacakPembelianService::class)->telusuri($kandidat)['total_beban_npk'] > 0);

        $this->assertNotNull($lpb, 'Seed harus punya satu LPB yang sebagian barangnya sudah dipakai lewat NPK.');

        return $lpb;
    }

    public function test_purchase_tracing_splits_every_receipt_without_losing_value(): void
    {
        $service = app(LacakPembelianService::class);

        $diperiksa = 0;

        foreach (PenerimaanBarang::where('document_type', 'GOODS')->get() as $lpb) {
            $data = $service->telusuri($lpb);

            $terlacak = $data['total_sisa_stok']
                + $data['total_beban_npk']
                + $data['total_selisih_opname']
                + $data['total_terjual']
                + $data['total_retur']
                + $data['total_hilang_transit']
                + $data['total_tidak_terlacak'];

            $this->assertEqualsWithDelta(
                $data['total_nilai_masuk'],
                $terlacak,
                0.01,
                "Sebaran nilai LPB {$lpb->id_lpb} tidak menutup total yang masuk."
            );

            $this->assertEqualsWithDelta(
                $data['total_nilai_pembelian'] + $data['total_biaya_tambahan'],
                $data['total_nilai_masuk'],
                0.01
            );

            $diperiksa++;
        }

        $this->assertGreaterThan(0, $diperiksa);
    }

    public function test_value_is_untraced_only_when_kitting_explains_it(): void
    {
        $service = app(LacakPembelianService::class);
        $db = \Illuminate\Support\Facades\DB::class;

        $adaPenyebab = $db::table('wms_perakitan_kit_detail')->exists();

        $totalTidakTerlacak = 0.0;
        $diperiksa = 0;

        foreach (PenerimaanBarang::where('document_type', 'GOODS')->get() as $lpb) {
            $hasil = $service->telusuri($lpb);
            $tidakTerlacak = round((float) $hasil['total_tidak_terlacak'], 2);
            $totalTidakTerlacak += $tidakTerlacak;
            $diperiksa++;

            $this->assertGreaterThanOrEqual(0.0, $tidakTerlacak, "LPB {$lpb->id_lpb}: ember tak terlacak tidak boleh negatif.");

            $this->assertLessThanOrEqual(
                round((float) $hasil['total_nilai_masuk'] + 0.01, 2),
                $tidakTerlacak,
                "LPB {$lpb->id_lpb}: nilai tak terlacak tidak boleh melebihi nilai penerimaannya sendiri."
            );
        }

        $this->assertGreaterThan(0, $diperiksa, 'Seed harus punya penerimaan barang untuk ditelusuri.');

        $this->assertSame(
            $adaPenyebab,
            round($totalTidakTerlacak, 2) > 0.0,
            'Sejak 2026-10-01 selisih transfer punya embernya sendiri (hilang_transit), jadi tinggal perakitan kit '
                . 'yang tidak mencatat tautan balik ke layer. Tanpa kitting seluruh nilai pembelian wajib terlacak habis; '
                . 'dengan kitting residu itu memang harus tampil dan bukan disembunyikan.'
        );
    }

    public function test_reversing_an_npk_moves_value_from_expense_back_to_stock(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        Auth::login($user);

        $service = app(LacakPembelianService::class);
        $lpb = $this->lpbDenganPemakaian();
        $sebelum = $service->telusuri($lpb);

        $npk = PemakaianBarang::where('status', PemakaianBarang::POSTED)->firstOrFail();
        app(InventoryReversalService::class)->reverseNpk($npk, 'Uji lacak pembelian');

        $sesudah = $service->telusuri($lpb);

        $this->assertLessThan($sebelum['total_beban_npk'], $sesudah['total_beban_npk']);
        $this->assertGreaterThan($sebelum['total_sisa_stok'], $sesudah['total_sisa_stok']);

        $this->assertEqualsWithDelta(
            $sebelum['total_beban_npk'] - $sesudah['total_beban_npk'],
            $sesudah['total_sisa_stok'] - $sebelum['total_sisa_stok'],
            0.01,
            'Nilai yang keluar dari ember beban harus persis sama dengan yang masuk ke ember stok.'
        );

        $this->assertEqualsWithDelta(
            $sebelum['total_tidak_terlacak'],
            $sesudah['total_tidak_terlacak'],
            0.01,
            'Pembalikan memindahkan nilai antar ember, jadi tidak boleh menambah maupun mengurangi nilai yang tak terlacak.'
        );
    }

    public function test_purchase_tracing_renders_and_exports_for_purchasing(): void
    {
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);
        $lpb = PenerimaanBarang::where('document_type', 'GOODS')->firstOrFail();

        $this->actingAs($purchasing)->get(route('lacak-pembelian.index'))->assertOk()->assertSee('Lacak Pembelian');
        $this->actingAs($purchasing)->get(route('lacak-pembelian.index', ['lpb' => $lpb->id_lpb]))
            ->assertOk()
            ->assertSee('Masih Stok');
        $this->actingAs($purchasing)->get(route('lacak-pembelian.pdf', ['lpb' => $lpb->id_lpb]))->assertOk();
        $this->actingAs($purchasing)->get(route('lacak-pembelian.excel', ['lpb' => $lpb->id_lpb]))->assertOk();
    }

    public function test_purchase_tracing_export_is_404_when_no_receipt_is_chosen(): void
    {
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);

        $this->actingAs($purchasing)->get(route('lacak-pembelian.pdf'))->assertNotFound();
        $this->actingAs($purchasing)->get(route('lacak-pembelian.excel'))->assertNotFound();
    }

    public function test_supplier_scorecard_measures_lead_time_and_flags_missing_qc_data(): void
    {
        $data = app(SupplierScorecardService::class)->ringkasan(today()->subYears(5), today());

        $this->assertGreaterThan(0, $data['baris']->count(), 'Seed harus punya minimal satu supplier dengan penerimaan.');

        foreach ($data['baris'] as $row) {
            $this->assertNotNull($row['lead_time_rata']);
            $this->assertGreaterThanOrEqual($row['lead_time_tercepat'], $row['lead_time_terlama']);
            $this->assertLessThanOrEqual($row['penerimaan'], $row['penerimaan_tepat']);

            if ((float) $row['qc_diperiksa'] > 0) {
                $this->assertNotNull($row['rasio_reject'], 'Supplier yang sudah punya pemeriksaan kualitas harus melaporkan angka, bukan null.');
                continue;
            }

            $this->assertNull(
                $row['rasio_reject'],
                'Tanpa baris pemeriksaan kualitas, rasio reject harus null (belum ada data), bukan 0 persen.'
            );
        }
    }

    public function test_supplier_scorecard_target_lead_time_changes_the_punctuality_column(): void
    {
        $service = app(SupplierScorecardService::class);

        $ketat = $service->ringkasan(today()->subYears(5), today(), 1);
        $longgar = $service->ringkasan(today()->subYears(5), today(), 365);

        $this->assertSame(365, $longgar['target_lead_time']);

        foreach ($longgar['baris'] as $row) {
            $this->assertSame($row['penerimaan'], $row['penerimaan_tepat']);
            $this->assertEqualsWithDelta(100.0, $row['ketepatan'], 0.01);
        }

        $this->assertLessThanOrEqual(
            $longgar['baris']->sum('penerimaan_tepat'),
            $ketat['baris']->sum('penerimaan_tepat')
        );
    }

    public function test_supplier_scorecard_renders_and_exports(): void
    {
        $accounting = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);

        $this->actingAs($accounting)->get(route('supplier-scorecard.index'))->assertOk()->assertSee('Kartu Skor Supplier');
        $this->actingAs($accounting)->get(route('supplier-scorecard.pdf'))->assertOk();
        $this->actingAs($accounting)->get(route('supplier-scorecard.excel'))->assertOk();
    }

    public function test_procurement_analytics_are_closed_to_unrelated_roles(): void
    {
        $warehouse = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);

        $this->actingAs($warehouse)->get(route('lacak-pembelian.index'))->assertForbidden();
        $this->actingAs($warehouse)->get(route('supplier-scorecard.index'))->assertForbidden();
    }
}
