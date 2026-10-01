<?php

namespace Tests\Feature;

use App\Models\Gudang;
use App\Models\LayerPersediaan;
use App\Models\PengirimanSubkontrak;
use App\Models\StokGudang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingReconciliationService;
use App\Services\SubkontrakService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class SubkontrakPersediaanTest extends TestCase
{
    use DatabaseTransactions;

    private function operator(): User
    {
        return User::where('type', User::ROLE_WAREHOUSE)->firstOrFail();
    }

    private function stok(float $minimal = 2): StokGudang
    {
        return StokGudang::whereRaw('stok_tersedia - stok_direservasi >= ?', [$minimal])
            ->whereHas('gudang', fn ($query) => $query->where('jenis', Gudang::NORMAL)
                ->where('kode', '!=', SubkontrakService::KODE_GUDANG))
            ->firstOrFail();
    }

    private function kirim(float $jumlah = 2): PengirimanSubkontrak
    {
        $stok = $this->stok($jumlah);

        return app(SubkontrakService::class)->kirim([
            'tanggal' => today()->toDateString(),
            'supplier_id' => Supplier::firstOrFail()->id,
            'gudang_asal_id' => $stok->gudang_id,
            'estimasi_kembali' => today()->addDays(7)->toDateString(),
            'keperluan' => 'Jasa bordir',
            'details' => [['bahan_id' => $stok->bahan_id, 'jumlah' => $jumlah]],
        ], $this->operator());
    }

    public function test_sending_stock_to_a_vendor_moves_it_to_the_subcontract_warehouse_without_losing_value(): void
    {
        $stok = $this->stok(2);
        $gudangAsal = (int) $stok->gudang_id;
        $bahanId = (int) $stok->bahan_id;

        $nilaiLayerSebelum = round((float) LayerPersediaan::whereNotIn('stock_status', ['REVERSED', 'TRANSFER_SHORTAGE'])
            ->sum(\Illuminate\Support\Facades\DB::raw('remaining_quantity * unit_cost')), 2);
        $stokAsalSebelum = round((float) $stok->stok_tersedia, 6);

        $pengiriman = app(SubkontrakService::class)->kirim([
            'tanggal' => today()->toDateString(),
            'supplier_id' => Supplier::firstOrFail()->id,
            'gudang_asal_id' => $gudangAsal,
            'details' => [['bahan_id' => $bahanId, 'jumlah' => 2]],
        ], $this->operator());

        $gudangSubkontrak = app(SubkontrakService::class)->gudangSubkontrak();

        $this->assertSame(PengirimanSubkontrak::DIKIRIM, $pengiriman->status);

        $this->assertEqualsWithDelta(
            $stokAsalSebelum - 2,
            round((float) StokGudang::where('gudang_id', $gudangAsal)->where('bahan_id', $bahanId)->value('stok_tersedia'), 6),
            0.000001,
            'Stok gudang asal harus berkurang sebesar yang dikirim ke vendor.'
        );

        $this->assertEqualsWithDelta(
            2,
            round((float) StokGudang::where('gudang_id', $gudangSubkontrak->id)->where('bahan_id', $bahanId)->value('stok_tersedia'), 6),
            0.000001,
            'Barang di vendor tetap stok milik kita, hanya berpindah gudang.'
        );

        $this->assertEqualsWithDelta(
            $nilaiLayerSebelum,
            round((float) LayerPersediaan::whereNotIn('stock_status', ['REVERSED', 'TRANSFER_SHORTAGE'])
                ->sum(\Illuminate\Support\Facades\DB::raw('remaining_quantity * unit_cost')), 2),
            0.05,
            'Mengirim ke subkontrak bukan penjualan: nilai persediaan perusahaan tidak boleh berubah.'
        );
    }

    public function test_receiving_the_goods_back_closes_the_document_and_returns_the_stock(): void
    {
        $pengiriman = $this->kirim(2);
        $detail = $pengiriman->details->first();
        $gudangAsal = (int) $pengiriman->gudang_asal_id;
        $bahanId = (int) $detail->bahan_id;

        $stokSebelumKembali = round((float) StokGudang::where('gudang_id', $gudangAsal)->where('bahan_id', $bahanId)->value('stok_tersedia'), 6);

        $hasil = app(SubkontrakService::class)->terima(
            $pengiriman,
            [$detail->id => 2],
            today()->toDateString(),
            $this->operator()
        );

        $this->assertSame(PengirimanSubkontrak::SELESAI, $hasil->status);
        $this->assertEqualsWithDelta(
            $stokSebelumKembali + 2,
            round((float) StokGudang::where('gudang_id', $gudangAsal)->where('bahan_id', $bahanId)->value('stok_tersedia'), 6),
            0.000001
        );
    }

    public function test_a_partial_return_keeps_the_document_open(): void
    {
        $pengiriman = $this->kirim(2);
        $detail = $pengiriman->details->first();

        $hasil = app(SubkontrakService::class)->terima(
            $pengiriman,
            [$detail->id => 1],
            today()->toDateString(),
            $this->operator()
        );

        $this->assertSame(PengirimanSubkontrak::SEBAGIAN_KEMBALI, $hasil->status);
        $this->assertEqualsWithDelta(1, $hasil->details->first()->sisaDiVendor(), 0.000001);
    }

    public function test_returning_more_than_was_sent_is_refused(): void
    {
        $pengiriman = $this->kirim(2);
        $detail = $pengiriman->details->first();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('melebihi yang masih ada di vendor');

        app(SubkontrakService::class)->terima($pengiriman, [$detail->id => 5], today()->toDateString(), $this->operator());
    }

    public function test_the_reconciliation_invariants_survive_a_subcontract_round_trip(): void
    {
        $pengiriman = $this->kirim(2);

        foreach (app(AccountingReconciliationService::class)->checks() as $cek) {
            $this->assertSame(0, (int) $cek['invalid'], "Invarian {$cek['key']} rusak saat barang ada di vendor.");
        }

        app(SubkontrakService::class)->terima(
            $pengiriman,
            [$pengiriman->details->first()->id => 2],
            today()->toDateString(),
            $this->operator()
        );

        foreach (app(AccountingReconciliationService::class)->checks() as $cek) {
            $this->assertSame(0, (int) $cek['invalid'], "Invarian {$cek['key']} rusak setelah barang kembali.");
        }
    }

    public function test_an_overdue_shipment_reaches_the_daily_reminder(): void
    {
        $pengiriman = $this->kirim(1);
        $pengiriman->update(['estimasi_kembali' => today()->subDays(3)->toDateString()]);

        $pengingat = app(\App\Services\PengingatService::class)->subkontrakBelumKembali();

        $this->assertTrue(
            $pengingat->contains(fn ($baris) => str_contains($baris['label'] ?? '', $pengiriman->nomor)),
            'Barang yang menginap di vendor melewati estimasi kembali harus muncul di pengingat, seperti gate pass.'
        );
    }

    public function test_the_page_is_open_to_the_warehouse_and_closed_to_finance(): void
    {
        $this->actingAs($this->operator())->get(route('subkontrak.index'))->assertOk();

        $finance = User::factory()->create(['type' => User::ROLE_FINANCE, 'is_active' => true]);
        $this->actingAs($finance)->get(route('subkontrak.index'))->assertForbidden();
    }

    public function test_sending_through_http_records_the_document(): void
    {
        $stok = $this->stok(1);
        $sebelum = PengirimanSubkontrak::count();

        $this->actingAs($this->operator())
            ->post(route('subkontrak.store'), [
                'tanggal' => today()->toDateString(),
                'supplier_id' => Supplier::firstOrFail()->id,
                'gudang_asal_id' => $stok->gudang_id,
                'estimasi_kembali' => today()->addDays(5)->toDateString(),
                'details' => [['bahan_id' => $stok->bahan_id, 'jumlah' => 1]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($sebelum + 1, PengirimanSubkontrak::count());
    }
}
