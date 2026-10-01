<?php

namespace Tests\Feature;

use App\Models\AccountingPeriodLock;
use App\Models\Aset;
use App\Models\BarangTitipan;
use App\Models\Gudang;
use App\Models\Jurnal;
use App\Models\LayerPersediaan;
use App\Models\Pelanggan;
use App\Models\PenyusutanAset;
use App\Models\StokGudang;
use App\Models\User;
use App\Services\PenjualanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;
use Throwable;

class JadwalDanKontrolPeriodeTest extends TestCase
{
    use DatabaseTransactions;

    private function kunciPeriode(string $awal, string $akhir): AccountingPeriodLock
    {
        return AccountingPeriodLock::create([
            'period_start' => $awal,
            'period_end' => $akhir,
            'status' => 'LOCKED',
            'reason' => 'Uji kontrol periode',
            'locked_by' => User::firstOrFail()->id,
            'locked_at' => now(),
        ]);
    }

    public function test_monthly_depreciation_runs_from_the_scheduler_without_a_logged_in_user(): void
    {
        Auth::logout();

        $aset = Aset::where('status', Aset::ACTIVE)
            ->whereIn('depreciation_method', [Aset::STRAIGHT_LINE, Aset::DECLINING_BALANCE])
            ->get()
            ->first(fn (Aset $a) => $a->suggestedMonthlyDepreciation() > 0
                && round((float) $a->book_value - (float) $a->residual_value, 2) > 0.01);

        $this->assertNotNull($aset, 'Data demo harus punya minimal satu aset yang masih bisa disusutkan.');

        $periode = now()->subMonthNoOverflow()->format('Y-m');
        PenyusutanAset::where('aset_id', $aset->id)->where('period_label', $periode)->delete();

        $sebelum = PenyusutanAset::count();
        $kode = $this->artisan('wms:penyusutan-bulanan')->run();

        $this->assertSame(
            0,
            $kode,
            'Penyusutan terjadwal harus berhasil tanpa user login. Sebelum 2026-09-24 posted_by NOT NULL membuat setiap aset gagal.'
        );

        $this->assertGreaterThan($sebelum, PenyusutanAset::count(), 'Perintah terjadwal harus benar-benar memposting penyusutan.');
    }

    public function test_the_depreciation_command_fails_loudly_when_an_asset_cannot_be_posted(): void
    {
        Auth::logout();

        $periode = now()->subMonthNoOverflow()->format('Y-m');
        PenyusutanAset::where('period_label', $periode)->delete();

        $this->kunciPeriode(
            now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
            now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
        );

        $kode = $this->artisan('wms:penyusutan-bulanan')->run();

        $this->assertSame(
            1,
            $kode,
            'Perintah wajib keluar dengan kode gagal ketika ada aset yang tidak bisa diposting, kalau tidak penjadwal melihatnya sebagai sukses.'
        );
    }

    public function test_a_locked_period_refuses_a_delivery_and_leaves_no_trace(): void
    {
        $user = User::where('type', User::ROLE_WAREHOUSE)->firstOrFail();
        $stok = StokGudang::whereRaw('stok_tersedia - stok_direservasi >= 1')
            ->whereHas('gudang', fn ($query) => $query->where('jenis', Gudang::NORMAL))
            ->firstOrFail();

        $pelanggan = Pelanggan::create([
            'kode' => 'KUNCI-' . random_int(1000, 9999),
            'nama' => 'PT Uji Kunci Periode',
            'termin_hari' => 30,
            'is_active' => true,
        ]);

        $service = app(PenjualanService::class);

        $pesanan = $service->buatPesanan([
            'tanggal' => today()->toDateString(),
            'pelanggan_id' => $pelanggan->id,
            'gudang_id' => $stok->gudang_id,
            'is_ppn' => true,
            'tarif_ppn' => 11,
            'details' => [['bahan_id' => $stok->bahan_id, 'jumlah' => 1, 'harga_satuan' => 50000]],
        ], $user);

        $suratJalan = $service->buatSuratJalan($pesanan, [
            'tanggal' => today()->toDateString(),
            'details' => [['pesanan_penjualan_detail_id' => $pesanan->details->first()->id, 'jumlah' => 1]],
        ], $user);

        $this->kunciPeriode(today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString());

        $layerSebelum = round((float) LayerPersediaan::sum('remaining_quantity'), 6);
        $stokSebelum = round((float) $stok->fresh()->stok_tersedia, 6);
        $jurnalSebelum = Jurnal::count();

        $ditolak = false;

        try {
            $service->postingSuratJalan($suratJalan, $user);
        } catch (Throwable $e) {
            $ditolak = true;
            $this->assertStringContainsString('dikunci', $e->getMessage());
        }

        $this->assertTrue($ditolak, 'Surat jalan bertanggal di periode terkunci harus ditolak.');
        $this->assertSame('DRAFT', $suratJalan->fresh()->status);
        $this->assertSame($layerSebelum, round((float) LayerPersediaan::sum('remaining_quantity'), 6), 'Penolakan tidak boleh menyisakan layer yang sudah terpakai.');
        $this->assertSame($stokSebelum, round((float) $stok->fresh()->stok_tersedia, 6));
        $this->assertSame($jurnalSebelum, Jurnal::count());
    }

    public function test_closing_a_consignment_validates_its_input_instead_of_exploding(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_WAREHOUSE, 'is_active' => true]);
        $titipan = BarangTitipan::where('status', BarangTitipan::DITERIMA)->firstOrFail();

        $this->actingAs($user)
            ->post(route('barang-titipan.selesai', $titipan), [
                'status' => BarangTitipan::DIKEMBALIKAN,
                'tanggal_kembali' => 'bukan-tanggal',
            ])
            ->assertSessionHasErrors('tanggal_kembali');

        $this->actingAs($user)
            ->post(route('barang-titipan.selesai', $titipan), [
                'status' => 'NGARANG',
                'tanggal_kembali' => today()->toDateString(),
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(
            BarangTitipan::DITERIMA,
            $titipan->fresh()->status,
            'Input yang ditolak tidak boleh mengubah catatan titipan.'
        );
    }
}
