<?php

namespace Tests\Feature;

use App\Models\Aset;
use App\Models\BaganAkun;
use App\Models\Jurnal;
use App\Models\KategoriAset;
use App\Models\PenyusutanAset;
use App\Models\User;
use App\Services\AsetAccountingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class PenyusutanGandaTest extends TestCase
{
    use DatabaseTransactions;

    private function aset(User $user): Aset
    {
        return Aset::create([
            'nomor_aset' => 'DEP-GANDA-' . random_int(1000, 9999),
            'kategori_aset_id' => KategoriAset::where('code', 'EQUIPMENT')->firstOrFail()->id,
            'name' => 'Aset Uji Penyusutan Ganda',
            'condition' => 'BAIK',
            'acquisition_date' => today()->subYear(),
            'acquisition_type' => 'OPENING_BALANCE',
            'acquisition_credit_coa_id' => BaganAkun::where('kode_akun', '3102')->value('id'),
            'acquisition_cost' => 2400000,
            'residual_value' => 0,
            'useful_life_months' => 24,
            'depreciation_method' => 'STRAIGHT_LINE',
            'accumulated_depreciation' => 0,
            'book_value' => 2400000,
            'status' => 'ACTIVE',
            'created_by' => $user->id,
        ]);
    }

    public function test_depreciating_the_same_asset_and_period_twice_posts_only_once(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        Auth::login($user);
        $aset = $this->aset($user);
        $service = app(AsetAccountingService::class);
        $data = ['posting_date' => today()->toDateString(), 'period_label' => 'Periode Uji Ganda'];

        $service->depreciate($aset, $data);

        try {
            $service->depreciate($aset, $data);
            $this->fail('Penyusutan kedua untuk periode yang sama harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sudah disusutkan', $e->getMessage());
        }

        $this->assertSame(1, PenyusutanAset::where('aset_id', $aset->id)->where('period_label', 'Periode Uji Ganda')->count());
        $this->assertSame(1, Jurnal::where('sumber_transaksi', 'ASSET_DEPRECIATION')
            ->whereIn('reff_id', PenyusutanAset::where('aset_id', $aset->id)->pluck('id'))->count());
        $this->assertEqualsWithDelta(100000.0, (float) $aset->fresh()->accumulated_depreciation, 0.01);
    }

    public function test_a_second_depreciation_request_through_the_route_is_a_validation_error_not_a_500(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $aset = $this->aset($user);
        $payload = ['posting_date' => today()->toDateString(), 'period_label' => 'Periode Rute Ganda'];

        $this->actingAs($user)->post(route('aset.depreciate', $aset), $payload)->assertSessionHasNoErrors();

        $kedua = $this->actingAs($user)->from(route('aset.show', $aset))->post(route('aset.depreciate', $aset), $payload);
        $kedua->assertRedirect(route('aset.show', $aset));
        $kedua->assertSessionHasErrors('penyusutan');
        $this->assertStringContainsString('sudah disusutkan', session('errors')->first('penyusutan'));

        $json = $this->actingAs($user)->postJson(route('aset.depreciate', $aset), $payload);
        $json->assertStatus(422)->assertJson(['success' => false]);

        $this->assertSame(1, PenyusutanAset::where('aset_id', $aset->id)->where('period_label', 'Periode Rute Ganda')->count());
    }

    public function test_the_schema_itself_refuses_a_duplicate_period_for_one_asset(): void
    {
        $this->assertTrue(
            collect(Schema::getIndexes('wms_penyusutan_asets'))->contains(
                fn ($index) => $index['unique'] && $index['columns'] === ['aset_id', 'period_label']
            ),
            'wms_penyusutan_asets harus unik pada (aset_id, period_label).'
        );
    }

    public function test_the_automatic_run_stays_idempotent(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        Auth::login($user);
        $aset = $this->aset($user);
        $service = app(AsetAccountingService::class);
        $tanggal = today()->toDateString();

        $service->runAutomaticDepreciation($tanggal, 'Otomatis Ganda');
        $kedua = $service->runAutomaticDepreciation($tanggal, 'Otomatis Ganda');

        $this->assertSame(1, $aset->depreciations()->where('period_label', 'Otomatis Ganda')->count());
        $this->assertSame([], $kedua['posted']);
        $this->assertSame([], $kedua['failed']);
    }
}
