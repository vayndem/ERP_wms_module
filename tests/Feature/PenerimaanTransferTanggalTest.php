<?php

namespace Tests\Feature;

use App\Models\AccountingPeriodLock;
use App\Models\Gudang;
use App\Models\Jurnal;
use App\Models\StokGudang;
use App\Models\TransferGudang;
use App\Models\User;
use App\Services\AccountingReconciliationService;
use App\Services\TransferGudangService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PenerimaanTransferTanggalTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function transferDiajukan(float $jumlah = 2): TransferGudang
    {
        $operator = User::factory()->create(['type' => User::ROLE_WAREHOUSE, 'is_active' => true]);
        Auth::setUser($operator);

        $stok = StokGudang::whereRaw('stok_tersedia - stok_direservasi >= ?', [$jumlah])
            ->whereHas('gudang', fn ($q) => $q->where('jenis', Gudang::NORMAL))
            ->firstOrFail();

        $tujuan = Gudang::where('jenis', Gudang::NORMAL)
            ->where('id', '!=', $stok->gudang_id)
            ->where('boleh_transfer', true)
            ->firstOrFail();

        $transfer = TransferGudang::create([
            'nomor_transfer' => 'TRF-TGL-' . random_int(10000, 99999),
            'tanggal' => today(),
            'gudang_asal_id' => $stok->gudang_id,
            'gudang_tujuan_id' => $tujuan->id,
            'status' => TransferGudang::DIAJUKAN,
            'dibuat_oleh' => $operator->id,
        ]);

        $transfer->details()->create(['bahan_id' => $stok->bahan_id, 'jumlah' => $jumlah]);

        return $transfer->fresh('details');
    }

    public function test_a_transfer_shipped_in_a_since_locked_period_can_still_be_received_and_is_journalled_on_the_receipt_date(): void
    {
        $service = app(TransferGudangService::class);
        $hariKirim = today();
        $hariTerima = today()->addDays(3);

        $transfer = $service->konfirmasi($this->transferDiajukan());

        AccountingPeriodLock::create([
            'period_start' => $hariKirim->toDateString(),
            'period_end' => $hariKirim->copy()->addDay()->toDateString(),
            'status' => 'LOCKED',
            'reason' => 'Uji penerimaan transfer',
            'locked_by' => User::firstOrFail()->id,
            'locked_at' => now(),
        ]);

        Carbon::setTestNow($hariTerima->copy()->setTime(10, 0));

        $diterima = $service->terima($transfer);

        $this->assertSame(TransferGudang::DITERIMA, $diterima->status);

        $kirim = Jurnal::where('sumber_transaksi', 'TRANSFER_GUDANG_KIRIM')->where('reff_id', $transfer->id)->firstOrFail();
        $terima = Jurnal::where('sumber_transaksi', 'TRANSFER_GUDANG')->where('reff_id', $transfer->id)->firstOrFail();

        $this->assertSame($hariKirim->toDateString(), $kirim->tanggal->toDateString(), 'Jurnal pengiriman tetap bertanggal pengiriman.');
        $this->assertSame($hariTerima->toDateString(), $terima->tanggal->toDateString(), 'Jurnal penerimaan harus bertanggal penerimaan nyata.');

        $transit = collect(app(AccountingReconciliationService::class)->checks())->firstWhere('key', 'transit');
        $this->assertSame(0, $transit['invalid'], 'Invarian transit harus tetap valid.');
    }

    public function test_receiving_inside_a_locked_period_is_still_refused(): void
    {
        $service = app(TransferGudangService::class);
        $transfer = $service->konfirmasi($this->transferDiajukan());

        AccountingPeriodLock::create([
            'period_start' => today()->subDay()->toDateString(),
            'period_end' => today()->addDay()->toDateString(),
            'status' => 'LOCKED',
            'reason' => 'Uji penerimaan transfer',
            'locked_by' => User::firstOrFail()->id,
            'locked_at' => now(),
        ]);

        $this->expectException(\RuntimeException::class);
        $service->terima($transfer);
    }
}
