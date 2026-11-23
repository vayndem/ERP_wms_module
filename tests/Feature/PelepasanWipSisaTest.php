<?php

namespace Tests\Feature;

use App\Models\AccountingSetting;
use App\Models\Bahan;
use App\Models\DataPesanan;
use App\Models\Gudang;
use App\Models\Jurnal;
use App\Models\Pelanggan;
use App\Models\PemakaianBarang;
use App\Models\PesananPenjualan;
use App\Models\StokGudang;
use App\Models\SuratJalan;
use App\Models\User;
use App\Services\AccountingReconciliationService;
use App\Services\DataPesananService;
use App\Services\PenjualanService;
use App\Services\StokGudangService;
use App\Services\WmsAccountingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PelepasanWipSisaTest extends TestCase
{
    use DatabaseTransactions;

    private function saldoWipGl(): float
    {
        $akun = AccountingSetting::accountId(AccountingSetting::BARANG_DALAM_PROSES);

        return round((float) Jurnal::berlaku()
            ->join('wms_jurnal_detail as jd', 'jd.jurnal_id', '=', 'wms_jurnal.id')
            ->where('jd.coa_id', $akun)
            ->sum(DB::raw('jd.debit - jd.kredit')), 2);
    }

    private function npk(DataPesanan $wo, StokGudang $stok, User $user, float $jumlah): PemakaianBarang
    {
        $bahan = Bahan::findOrFail($stok->bahan_id);

        $npk = PemakaianBarang::create([
            'kode' => 'NPK-SISA-' . random_int(10000, 99999),
            'kode_datapesanan' => $wo->nomor,
            'data_pesanan_id' => $wo->id,
            'tanggal' => today()->toDateString(),
            'id_barang' => $bahan->id,
            'id_gudang_asal' => $stok->gudang_id,
            'jumlah' => $jumlah,
            'jumlah_stok' => $bahan->toStockQuantity($jumlah),
            'satuan_transaksi' => $bahan->satuan,
            'id_user' => $user->id,
            'status' => PemakaianBarang::POSTED,
        ]);

        app(WmsAccountingService::class)->consumeStock($npk);
        app(StokGudangService::class)->keluar(
            (int) $stok->gudang_id, (int) $bahan->id, (float) $npk->jumlah_stok,
            (float) $npk->fresh()->harga_satuan, 'PENGELUARAN', 'NPK', $npk->id, $npk->kode
        );
        app(WmsAccountingService::class)->postNpk($npk->fresh());

        return $npk->fresh();
    }

    private function pesanan(User $user, StokGudang $stok, int $jumlah): PesananPenjualan
    {
        $pelanggan = Pelanggan::create([
            'kode' => 'CUST-SISA-' . random_int(1000, 9999),
            'nama' => 'PT Uji Sisa WIP',
            'termin_hari' => 30,
            'is_active' => true,
        ]);

        return app(PenjualanService::class)->buatPesanan([
            'tanggal' => today()->toDateString(),
            'pelanggan_id' => $pelanggan->id,
            'gudang_id' => $stok->gudang_id,
            'is_ppn' => true,
            'tarif_ppn' => 11,
            'details' => [['bahan_id' => Bahan::whereKeyNot($stok->bahan_id)->firstOrFail()->id, 'jumlah' => $jumlah, 'harga_satuan' => 90000]],
        ], $user);
    }

    public function test_the_last_shipment_releases_exactly_what_is_left_so_wip_returns_to_zero(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        Auth::login($user);

        $stok = StokGudang::whereRaw('stok_tersedia - stok_direservasi >= 5')
            ->whereHas('gudang', fn ($q) => $q->where('jenis', Gudang::NORMAL))
            ->firstOrFail();

        $glAwal = $this->saldoWipGl();

        $biayaSetara = fn (float $biaya, int $unit) => abs(round($biaya / $unit, 4) * $unit - $biaya);

        $probe = DB::transaction(function () use ($user, $stok, $biayaSetara) {
            $so = $this->pesanan($user, $stok, 7000);
            $wo = app(DataPesananService::class)->rilis(app(DataPesananService::class)->buat($so->details->first(), [
                'tanggal' => today()->toDateString(),
                'gudang_id' => $stok->gudang_id,
                'jumlah_rencana' => 7000,
            ], $user));
            $this->npk($wo, $stok, $user, 4);

            return [$so, $wo->fresh()];
        });

        [$so, $wo] = $probe;
        $biaya = $wo->totalBiaya();

        $unit = 7000;
        while ($biayaSetara($biaya, $unit) < 0.1 && $unit < 30000) {
            $unit++;
        }

        $this->assertGreaterThanOrEqual(0.1, $biayaSetara($biaya, $unit), 'Uji butuh jumlah selesai yang menimbulkan galat pembulatan.');

        $so->details->first()->update(['jumlah' => $unit]);

        $service = app(DataPesananService::class);
        $wo = $service->selesaikan($wo, $unit, $user);

        $bagian = intdiv($unit, 3);
        $penjualan = app(PenjualanService::class);

        foreach ([$bagian, $bagian, $unit - 2 * $bagian] as $jumlah) {
            $sj = $penjualan->buatSuratJalan($so->fresh('details'), [
                'tanggal' => today()->toDateString(),
                'details' => [['pesanan_penjualan_detail_id' => $so->details->first()->id, 'jumlah' => $jumlah]],
            ], $user);
            $penjualan->postingSuratJalan($sj, $user);
        }

        $wo = $wo->fresh();

        $this->assertSame(DataPesanan::DITUTUP, $wo->status);
        $this->assertEqualsWithDelta($biaya, $wo->totalDilepas(), 0.001, 'Total HPP yang dilepas harus sama persis dengan biaya yang diserap.');
        $this->assertEqualsWithDelta($glAwal, $this->saldoWipGl(), 0.001, 'Akun 1303 harus kembali ke saldo awal setelah semua pengiriman.');

        $cek = collect(app(AccountingReconciliationService::class)->checks())->firstWhere('key', 'wip');
        $this->assertSame(0, $cek['invalid'], 'Invarian wip harus tetap valid.');
    }

    public function test_pelepasan_parsial_yang_dibulatkan_ke_atas_tidak_melebihi_total_biaya(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        Auth::login($user);

        $stok = StokGudang::whereRaw('stok_tersedia - stok_direservasi >= 5')
            ->whereHas('gudang', fn ($q) => $q->where('jenis', Gudang::NORMAL))
            ->firstOrFail();

        $glAwal = $this->saldoWipGl();
        $service = app(DataPesananService::class);
        $penjualan = app(PenjualanService::class);

        $so = $this->pesanan($user, $stok, 7000);
        $wo = $service->rilis($service->buat($so->details->first(), [
            'tanggal' => today()->toDateString(),
            'gudang_id' => $stok->gudang_id,
            'jumlah_rencana' => 7000,
        ], $user));
        $this->npk($wo, $stok, $user, 4);
        $wo = $service->selesaikan($wo->fresh(), 7000, $user);
        $biaya = $wo->totalBiaya();

        DB::table('wms_data_pesanan')->where('id', $wo->id)->update([
            'biaya_per_unit' => round($biaya / 7000, 4) + 0.01,
        ]);

        $sj = $penjualan->buatSuratJalan($so->fresh('details'), [
            'tanggal' => today()->toDateString(),
            'details' => [['pesanan_penjualan_detail_id' => $so->details->first()->id, 'jumlah' => 6999]],
        ], $user);
        $penjualan->postingSuratJalan($sj, $user);

        $wo = $wo->fresh();

        $this->assertEqualsWithDelta($biaya, $wo->totalDilepas(), 0.001, 'Pelepasan parsial tidak boleh melebihi biaya yang diserap.');
        $this->assertEqualsWithDelta($glAwal, $this->saldoWipGl(), 0.001, 'Akun 1303 tidak boleh tertinggal kredit.');
        $terakhir = $penjualan->buatSuratJalan($so->fresh('details'), [
            'tanggal' => today()->toDateString(),
            'details' => [['pesanan_penjualan_detail_id' => $so->details->first()->id, 'jumlah' => 1]],
        ], $user);

        $this->actingAs($user)
            ->post(route('surat-jalan.post', $terakhir))
            ->assertSessionHasNoErrors();

        $terakhir = $terakhir->fresh();
        $wo = $wo->fresh();

        $this->assertSame(SuratJalan::POSTED, $terakhir->status);
        $this->assertNull($terakhir->journal_id);
        $this->assertSame(DataPesanan::DITUTUP, $wo->status);
        $this->assertEqualsWithDelta($biaya, $wo->totalDilepas(), 0.001);
        $this->assertEqualsWithDelta($glAwal, $this->saldoWipGl(), 0.001);
        $this->assertSame(0.0, round((float) DB::table('wms_jurnal_detail')->join('wms_jurnal', 'wms_jurnal.id', '=', 'wms_jurnal_detail.jurnal_id')->whereIn('wms_jurnal.status', Jurnal::STATUS_BERLAKU)->selectRaw('SUM(debit) - SUM(kredit) as selisih')->value('selisih'), 2));

        $cek = collect(app(AccountingReconciliationService::class)->checks())->firstWhere('key', 'wip');
        $this->assertSame(0, $cek['invalid']);
    }

    public function test_halaman_daftar_perintah_kerja_tidak_menambah_query_per_baris(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        Auth::login($user);

        $stok = StokGudang::whereRaw('stok_tersedia - stok_direservasi >= 5')
            ->whereHas('gudang', fn ($q) => $q->where('jenis', Gudang::NORMAL))
            ->firstOrFail();

        $service = app(DataPesananService::class);
        $so = $this->pesanan($user, $stok, 100);
        $buat = fn () => $service->rilis($service->buat($so->details->first(), [
            'tanggal' => today()->toDateString(),
            'gudang_id' => $stok->gudang_id,
            'jumlah_rencana' => 5,
        ], $user));

        $hitung = function () use ($user) {
            $jumlah = 0;
            DB::listen(function () use (&$jumlah) {
                $jumlah++;
            });
            $respons = $this->actingAs($user)->get(route('data-pesanan.index', ['status' => DataPesanan::DIRILIS]))->assertOk();

            return [$jumlah, $respons->viewData('pesanan')->count()];
        };

        $buat();
        [$queryAwal, $barisAwal] = $hitung();

        for ($i = 0; $i < 10; $i++) {
            $buat();
        }

        [$queryAkhir, $barisAkhir] = $hitung();

        $this->assertGreaterThan($barisAwal, $barisAkhir);
        $this->assertSame($queryAwal, $queryAkhir, 'Jumlah query halaman daftar tidak boleh tumbuh dengan jumlah baris.');
    }

    public function test_saldo_wip_seluruh_dihitung_batch_dengan_jumlah_query_tetap(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        Auth::login($user);

        $stok = StokGudang::whereRaw('stok_tersedia - stok_direservasi >= 5')
            ->whereHas('gudang', fn ($q) => $q->where('jenis', Gudang::NORMAL))
            ->firstOrFail();

        $service = app(DataPesananService::class);
        $so = $this->pesanan($user, $stok, 100);

        $hitung = function () use ($service) {
            $jumlah = 0;
            DB::listen(function () use (&$jumlah) {
                $jumlah++;
            });
            $total = $service->saldoWipSeluruh();

            return [$total, $jumlah, DataPesanan::berjalan()->get()];
        };

        [, $queryAwal] = $hitung();

        for ($i = 0; $i < 20; $i++) {
            $wo = $service->rilis($service->buat($so->details->first(), [
                'tanggal' => today()->toDateString(),
                'gudang_id' => $stok->gudang_id,
                'jumlah_rencana' => 5,
            ], $user));
            DB::table('wms_data_pesanan_biaya')->insert([
                'data_pesanan_id' => $wo->id,
                'sumber' => 'UJI',
                'referensi_id' => $wo->id,
                'tanggal' => today()->toDateString(),
                'nilai' => 100 + $i,
                'keterangan' => 'Biaya uji batch',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        [$total, $queryAkhir, $pesanan] = $hitung();

        $this->assertLessThanOrEqual(3, $queryAkhir);
        $this->assertLessThanOrEqual(3, $queryAwal);
        $this->assertGreaterThanOrEqual(20, $pesanan->count());
        $this->assertEqualsWithDelta($pesanan->sum(fn (DataPesanan $item) => $item->saldoWip()), $total, 0.001);
        $this->assertEqualsWithDelta(DataPesanan::saldoWipBatch($pesanan), $total, 0.001);

        $cek = collect(app(AccountingReconciliationService::class)->checks())->firstWhere('key', 'wip');
        $this->assertIsArray($cek);
    }
}
