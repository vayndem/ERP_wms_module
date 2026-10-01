<?php

namespace Tests\Feature;

use App\Models\Gudang;
use App\Models\Pelanggan;
use App\Models\PesananPenjualan;
use App\Models\StokGudang;
use App\Models\User;
use App\Services\PenjualanService;
use App\Services\PlafonKreditService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class PlafonKreditTest extends TestCase
{
    use DatabaseTransactions;

    private function pelanggan(float $plafon): Pelanggan
    {
        return Pelanggan::create([
            'kode' => 'PLF-' . random_int(1000, 9999),
            'nama' => 'PT Uji Plafon',
            'termin_hari' => 30,
            'plafon_kredit' => $plafon,
            'is_active' => true,
        ]);
    }

    private function stok(): StokGudang
    {
        return StokGudang::whereRaw('stok_tersedia - stok_direservasi >= 1')
            ->whereHas('gudang', fn ($query) => $query->where('jenis', Gudang::NORMAL))
            ->firstOrFail();
    }

    private function dataPesanan(Pelanggan $pelanggan, float $harga, array $tambahan = []): array
    {
        $stok = $this->stok();

        return array_merge([
            'tanggal' => today()->toDateString(),
            'pelanggan_id' => $pelanggan->id,
            'gudang_id' => $stok->gudang_id,
            'is_ppn' => true,
            'tarif_ppn' => 11,
            'details' => [['bahan_id' => $stok->bahan_id, 'jumlah' => 1, 'harga_satuan' => $harga]],
        ], $tambahan);
    }

    public function test_an_order_beyond_the_credit_limit_is_refused_for_an_ordinary_salesperson(): void
    {
        $sales = User::where('type', User::ROLE_PURCHASING)->firstOrFail();
        $pelanggan = $this->pelanggan(1_000_000);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('melewati plafon kredit');

        app(PenjualanService::class)->buatPesanan($this->dataPesanan($pelanggan, 2_000_000), $sales);
    }

    public function test_an_order_within_the_limit_passes_untouched(): void
    {
        $sales = User::where('type', User::ROLE_PURCHASING)->firstOrFail();
        $pelanggan = $this->pelanggan(10_000_000);

        $pesanan = app(PenjualanService::class)->buatPesanan($this->dataPesanan($pelanggan, 1_000_000), $sales);

        $this->assertFalse($pesanan->plafon_dilampaui);
        $this->assertNull($pesanan->alasan_plafon);
    }

    public function test_a_customer_without_a_limit_is_never_blocked(): void
    {
        $sales = User::where('type', User::ROLE_PURCHASING)->firstOrFail();
        $pelanggan = $this->pelanggan(0);

        $pesanan = app(PenjualanService::class)->buatPesanan($this->dataPesanan($pelanggan, 99_000_000), $sales);

        $this->assertFalse($pesanan->plafon_dilampaui, 'Plafon 0 berarti tanpa batas, bukan batas nol.');
    }

    public function test_super_admin_can_break_the_limit_only_with_a_written_reason(): void
    {
        $admin = User::where('type', User::ROLE_SUPER_ADMIN)->firstOrFail();
        $pelanggan = $this->pelanggan(1_000_000);

        try {
            app(PenjualanService::class)->buatPesanan($this->dataPesanan($pelanggan, 5_000_000), $admin);
            $this->fail('Super Admin tanpa alasan tertulis seharusnya tetap ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('alasan', mb_strtolower($e->getMessage()));
        }

        $pesanan = app(PenjualanService::class)->buatPesanan(
            $this->dataPesanan($pelanggan, 5_000_000, ['alasan_plafon' => 'Disetujui direksi, pelanggan melunasi minggu depan.']),
            $admin
        );

        $this->assertTrue($pesanan->plafon_dilampaui);
        $this->assertSame('Disetujui direksi, pelanggan melunasi minggu depan.', $pesanan->alasan_plafon);
        $this->assertSame($admin->id, $pesanan->plafon_disetujui_oleh, 'Penembusan plafon wajib meninggalkan jejak siapa yang menyetujui.');
    }

    public function test_exposure_counts_receivables_undelivered_orders_and_delivered_but_unbilled(): void
    {
        $sales = User::where('type', User::ROLE_PURCHASING)->firstOrFail();
        $pelanggan = $this->pelanggan(50_000_000);
        $service = app(PlafonKreditService::class);

        $awal = $service->eksposur($pelanggan);
        $this->assertEqualsWithDelta(0.0, $awal['total'], 0.01, 'Pelanggan baru belum punya eksposur apa pun.');

        app(PenjualanService::class)->buatPesanan($this->dataPesanan($pelanggan, 2_000_000), $sales);

        $sesudah = $service->eksposur($pelanggan);

        $this->assertEqualsWithDelta(2_000_000, $sesudah['dipesan_belum_kirim'], 0.01,
            'Pesanan yang belum dikirim harus ikut menahan plafon, kalau tidak pelanggan bisa memesan berkali-kali.');
        $this->assertEqualsWithDelta(0.0, $sesudah['piutang'], 0.01);
    }

    public function test_the_second_order_sees_the_first_one_already_holding_the_limit(): void
    {
        $sales = User::where('type', User::ROLE_PURCHASING)->firstOrFail();
        $pelanggan = $this->pelanggan(3_000_000);

        app(PenjualanService::class)->buatPesanan($this->dataPesanan($pelanggan, 2_000_000), $sales);

        $this->expectException(RuntimeException::class);
        app(PenjualanService::class)->buatPesanan($this->dataPesanan($pelanggan, 2_000_000), $sales);
    }

    public function test_the_order_form_refuses_over_limit_through_http_and_keeps_nothing(): void
    {
        $sales = User::where('type', User::ROLE_PURCHASING)->firstOrFail();
        $pelanggan = $this->pelanggan(500_000);
        $stok = $this->stok();
        $sebelum = PesananPenjualan::count();

        $this->actingAs($sales)
            ->post(route('pesanan-penjualan.store'), [
                'tanggal' => today()->toDateString(),
                'pelanggan_id' => $pelanggan->id,
                'gudang_id' => $stok->gudang_id,
                'is_ppn' => 1,
                'tarif_ppn' => 11,
                'details' => [['bahan_id' => $stok->bahan_id, 'jumlah' => 1, 'harga_satuan' => 9_000_000]],
            ])
            ->assertSessionHasErrors('pesanan');

        $this->assertSame($sebelum, PesananPenjualan::count(), 'Pesanan yang ditolak plafon tidak boleh tersimpan sebagian.');
    }
}
