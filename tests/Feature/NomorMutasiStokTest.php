<?php

namespace Tests\Feature;

use App\Models\Bahan;
use App\Models\Gudang;
use App\Models\MutasiStok;
use App\Services\StokGudangService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NomorMutasiStokTest extends TestCase
{
    use DatabaseTransactions;

    public function test_mutasi_setelah_batas_seribu_tetap_berhasil_dengan_nomor_unik(): void
    {
        DB::table('document_sequences')->updateOrInsert(
            ['namespace' => 'INT_MTS_STK', 'period_key' => now()->format('ym')],
            ['last_number' => 999, 'created_at' => now(), 'updated_at' => now()],
        );

        [$gudang, $bahan] = $this->stokUji();
        $service = app(StokGudangService::class);

        $service->masuk($gudang->id, $bahan->id, 1, 100, 'UJI_1000', 'UJI_NOMOR_MUTASI', 1000, affectGlobal: false);
        $service->masuk($gudang->id, $bahan->id, 1, 100, 'UJI_1001', 'UJI_NOMOR_MUTASI', 1001, affectGlobal: false);

        $nomor = MutasiStok::where('jenis_referensi', 'UJI_NOMOR_MUTASI')->pluck('nomor_mutasi');

        $this->assertCount(2, $nomor);
        $this->assertCount(2, $nomor->unique());
        $this->assertTrue($nomor->every(fn (string $nilai) => preg_match('/^MTS-\d{6}-[0-9A-HJKMNP-TV-Z]{26}$/', $nilai) === 1));
        $this->assertSame(999, (int) DB::table('document_sequences')
            ->where('namespace', 'INT_MTS_STK')
            ->where('period_key', now()->format('ym'))
            ->value('last_number'));
    }

    public function test_rollback_tidak_membuat_nomor_mutasi_berikutnya_berulang(): void
    {
        [$gudang, $bahan] = $this->stokUji();
        $service = app(StokGudangService::class);

        DB::beginTransaction();
        $service->masuk($gudang->id, $bahan->id, 1, 100, 'UJI_ROLLBACK', 'UJI_NOMOR_ROLLBACK', 1, affectGlobal: false);
        $nomorDibatalkan = MutasiStok::where('jenis_referensi', 'UJI_NOMOR_ROLLBACK')->value('nomor_mutasi');
        $this->assertNotNull($nomorDibatalkan);
        DB::rollBack();

        $service->masuk($gudang->id, $bahan->id, 1, 100, 'UJI_SESUDAH_ROLLBACK', 'UJI_NOMOR_ROLLBACK', 2, affectGlobal: false);
        $nomorTersimpan = MutasiStok::where('jenis_referensi', 'UJI_NOMOR_ROLLBACK')->value('nomor_mutasi');

        $this->assertNotSame($nomorDibatalkan, $nomorTersimpan);
        $this->assertSame(1, MutasiStok::where('jenis_referensi', 'UJI_NOMOR_ROLLBACK')->count());
    }

    private function stokUji(): array
    {
        return [
            Gudang::where('jenis', Gudang::NORMAL)->firstOrFail(),
            Bahan::firstOrFail(),
        ];
    }
}
