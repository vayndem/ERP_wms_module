<?php

namespace Tests\Feature;

use App\Models\AccountingSetting;
use App\Models\Bahan;
use App\Models\DataPesanan;
use App\Models\Gudang;
use App\Models\Pelanggan;
use App\Models\PemakaianBarang;
use App\Models\PesananPenjualan;
use App\Models\StokGudang;
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

        return round((float) DB::table('wms_jurnal_detail as jd')
            ->join('wms_jurnal as j', 'j.id', '=', 'jd.jurnal_id')
            ->where('j.status', 'POSTED')
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
}
