<?php

namespace Tests\Feature;

use App\Models\FakturPembelian;
use App\Models\PembagianGudang;
use App\Models\User;
use App\Services\ReplenishmentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TanpaNPlusSatuPenskalaanTest extends TestCase
{
    use DatabaseTransactions;

    private const TAMBAHAN = 4;

    private function pengguna(int $peran): User
    {
        $pengguna = User::where('type', $peran)->where('is_active', true)->orderBy('id')->first();

        if (! $pengguna) {
            $this->markTestSkipped('Data demo untuk peran ini tidak ada.');
        }

        return $pengguna;
    }

    private function gandakan(string $tabel, array $unik = [], array $kunci = [], int $jumlah = self::TAMBAHAN, array $timpa = []): int
    {
        $sumber = DB::table($tabel)->when($kunci, fn ($q) => $q->where($kunci))->orderBy('id')->first();

        if (! $sumber) {
            $this->markTestSkipped("Tabel {$tabel} tidak punya baris demo untuk digandakan.");
        }

        $sumber = (array) $sumber;
        $id = $sumber['id'];
        unset($sumber['id']);

        for ($i = 1; $i <= $jumlah; $i++) {
            $salinan = $timpa + $sumber;

            foreach ($unik as $kolom) {
                if (array_key_exists($kolom, $sumber)) {
                    $salinan[$kolom] = $sumber[$kolom] === null ? null : 'Z'.$i.substr((string) $sumber[$kolom], 0, 12);
                }
            }

            DB::table($tabel)->insert($salinan);
        }

        return (int) $id;
    }

    private function hitung(User $pengguna, string $url, bool $ajax = false): int
    {
        $this->flushHeaders();
        $permintaan = $this->actingAs($pengguna);

        if ($ajax) {
            $permintaan = $permintaan->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $respons = $permintaan->get($url);
        $jumlah = count(DB::getQueryLog());
        DB::disableQueryLog();

        $respons->assertOk();

        return $jumlah;
    }

    private function nyatakanKonstan(User $pengguna, string $url, callable $tambah, bool $ajax = false): void
    {
        $sebelum = $this->hitung($pengguna, $url, $ajax);
        $tambah();
        $sesudah = $this->hitung($pengguna, $url, $ajax);

        if (filter_var(getenv('NPLUS1_REPORT'), FILTER_VALIDATE_BOOL)) {
            fwrite(STDOUT, "\nSKALA {$url}: {$sebelum} -> {$sesudah}\n");
        }

        $this->assertSame($sebelum, $sesudah, "Jumlah query {$url} bertambah dari {$sebelum} menjadi {$sesudah} setelah baris ditambah.");
    }

    public function test_daftar_pelanggan_tidak_menambah_query_per_pelanggan(): void
    {
        $this->nyatakanKonstan(
            $this->pengguna(User::ROLE_ACCOUNTING),
            route('pelanggan.index'),
            fn () => $this->gandakan('pelanggans', ['kode', 'npwp', 'nik', 'nitku', 'email'])
        );
    }

    public function test_bagan_akun_yajra_tidak_menambah_query_per_akun(): void
    {
        $this->nyatakanKonstan(
            $this->pengguna(User::ROLE_ACCOUNTING),
            route('bagan-akun.index').'?draw=1&start=0&length=100',
            fn () => $this->gandakan('wms_bagan_akun', ['kode_akun']),
            true
        );
    }

    public function test_faktur_pembelian_yajra_tidak_menambah_query_per_faktur(): void
    {
        $this->nyatakanKonstan(
            $this->pengguna(User::ROLE_ACCOUNTING),
            route('faktur-pembelian.index').'?draw=1&start=0&length=100',
            fn () => $this->gandakan('wms_faktur_pembelian', ['no_invoice', 'no_faktur_pajak']),
            true
        );
    }

    public function test_kategori_bahan_yajra_tidak_menambah_query_per_kategori(): void
    {
        $this->nyatakanKonstan(
            $this->pengguna(User::ROLE_ACCOUNTING),
            route('kategori-bahan.index').'?draw=1&start=0&length=100',
            fn () => $this->gandakan('kategori_bahans'),
            true
        );
    }

    public function test_kategori_aset_tidak_menambah_query_per_kategori(): void
    {
        $this->nyatakanKonstan(
            $this->pengguna(User::ROLE_ACCOUNTING),
            route('kategori-aset.index'),
            fn () => $this->gandakan('wms_kategori_asets', ['code', 'name'])
        );
    }

    public function test_transfer_gudang_untuk_produksi_tidak_menambah_query_per_transfer(): void
    {
        $produksi = $this->pengguna(User::ROLE_PRODUCTION);
        $gudangIds = $produksi->accessibleGudangIds('transfer');
        $transfer = DB::table('transfer_gudangs')->orderBy('id')->first();

        if (! $transfer || $gudangIds === []) {
            $this->markTestSkipped('Tidak ada transfer atau gudang yang dapat diakses peran produksi.');
        }

        $this->nyatakanKonstan(
            $produksi,
            route('transfer-gudangs.index'),
            fn () => $this->gandakan('transfer_gudangs', ['nomor_transfer'], ['id' => $transfer->id], self::TAMBAHAN, ['gudang_asal_id' => $gudangIds[0]])
        );
    }

    public function test_rincian_permintaan_yajra_tidak_menambah_query_per_baris(): void
    {
        $baris = DB::table('request_details')
            ->join('requests', 'requests.id', '=', 'request_details.request_id')
            ->where('requests.status', 'APPROVED')
            ->whereRaw('request_details.jumlah_acc > request_details.realisasi')
            ->select('request_details.id')
            ->first();

        if (! $baris) {
            $this->markTestSkipped('Tidak ada rincian permintaan yang masih berjalan.');
        }

        $this->nyatakanKonstan(
            $this->pengguna(User::ROLE_PURCHASING),
            route('requestdetail.index').'?draw=1&start=0&length=100',
            fn () => $this->gandakan('request_details', [], ['id' => $baris->id]),
            true
        );
    }

    public function test_pesanan_penjualan_show_tidak_menambah_query_per_baris(): void
    {
        $detail = DB::table('wms_pesanan_penjualan_detail')->orderBy('id')->first();

        if (! $detail) {
            $this->markTestSkipped('Tidak ada pesanan penjualan demo.');
        }

        $this->nyatakanKonstan(
            $this->pengguna(User::ROLE_PURCHASING),
            route('pesanan-penjualan.show', $detail->pesanan_penjualan_id),
            fn () => $this->gandakan('wms_pesanan_penjualan_detail', [], ['id' => $detail->id])
        );
    }

    public function test_cross_dock_tidak_menambah_query_per_baris_pesanan(): void
    {
        $detail = DB::table('wms_pesanan_penjualan_detail as d')
            ->join('wms_pesanan_penjualan as p', 'p.id', '=', 'd.pesanan_penjualan_id')
            ->where('p.status', 'OPEN')
            ->select('d.id')
            ->orderBy('d.id')
            ->first();

        if (! $detail) {
            $this->markTestSkipped('Tidak ada pesanan penjualan terbuka.');
        }

        $this->nyatakanKonstan(
            $this->pengguna(User::ROLE_WAREHOUSE),
            route('cross-dock.index'),
            fn () => $this->gandakan('wms_pesanan_penjualan_detail', [], ['id' => $detail->id])
        );
    }

    public function test_revaluasi_kurs_tidak_menambah_query_per_faktur_valas(): void
    {
        $faktur = DB::table('wms_faktur_pembelian')->whereNotNull('mata_uang_asing')->whereNotNull('kurs')->where('sisa_tagihan', '>', 0)->orderBy('id')->first();

        if (! $faktur) {
            $this->markTestSkipped('Tidak ada faktur valas terbuka.');
        }

        $this->nyatakanKonstan(
            $this->pengguna(User::ROLE_ACCOUNTING),
            route('revaluasi-kurs.index'),
            fn () => $this->gandakan('wms_faktur_pembelian', ['no_invoice', 'no_faktur_pajak'], ['id' => $faktur->id])
        );
    }

    public function test_uang_muka_tersedia_tidak_menambah_query_per_pembayaran(): void
    {
        $pembayaran = DB::table('wms_pembayaran_faktur')->where('status', 'POSTED')->whereNotNull('supplier_id')->orderBy('id')->first();

        if (! $pembayaran) {
            $this->markTestSkipped('Tidak ada pembayaran faktur demo.');
        }

        DB::table('wms_pembayaran_faktur')->where('id', $pembayaran->id)->update(['jenis_selisih' => 'UANG_MUKA_SUPPLIER', 'kelebihan_pembayaran' => 500000]);

        $this->nyatakanKonstan(
            $this->pengguna(User::ROLE_FINANCE),
            route('pembayaran-faktur.available-advances', $pembayaran->supplier_id),
            fn () => $this->gandakan('wms_pembayaran_faktur', ['payment_number'], ['id' => $pembayaran->id])
        );
    }

    public function test_retur_pembelian_create_tidak_menambah_query_per_penerimaan(): void
    {
        $lpb = DB::table('wms_penerimaan_barang')
            ->where('status', 'POSTED')
            ->where('document_type', '!=', 'SERVICE_BAP')
            ->whereNotNull('no_invoice')
            ->orderBy('id')
            ->first();

        if (! $lpb) {
            $this->markTestSkipped('Tidak ada penerimaan barang yang sudah ditagih.');
        }

        $this->nyatakanKonstan(
            $this->pengguna(User::ROLE_PURCHASING),
            route('retur-pembelian.create'),
            fn () => $this->gandakan('wms_penerimaan_barang', ['id_lpb'], ['id' => $lpb->id])
        );
    }

    public function test_data_pesanan_show_menghitung_biaya_sekali(): void
    {
        $pesanan = DB::table('wms_data_pesanan')->orderBy('id')->first();

        if (! $pesanan) {
            $this->markTestSkipped('Tidak ada perintah kerja demo.');
        }

        $this->actingAs($this->pengguna(User::ROLE_PRODUCTION));
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('data-pesanan.show', $pesanan->id))->assertOk();
        $agregat = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'sum(`nilai`)') && str_contains($q['query'], 'wms_data_pesanan_biaya'))->count();
        DB::disableQueryLog();

        $this->assertSame(0, $agregat);
    }

    public function test_akses_gudang_pengguna_dihitung_sekali_per_permintaan_dan_tetap_segar(): void
    {
        $gudang = $this->pengguna(User::ROLE_WAREHOUSE);
        $gudang->unsetRelation('pembagianGudangs');

        DB::flushQueryLog();
        DB::enableQueryLog();

        for ($i = 0; $i < 6; $i++) {
            $gudang->accessibleGudangIds('transfer');
        }

        $jumlah = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $jumlah);

        $sebelum = $gudang->accessibleGudangIds('transfer');
        $baru = DB::table('gudangs')->where('aktif', true)->whereNotIn('id', DB::table('pembagian_gudangs')->where('user_id', $gudang->id)->pluck('gudang_id')->all() ?: [0])->value('id');

        if ($baru) {
            PembagianGudang::create(['user_id' => $gudang->id, 'gudang_id' => $baru, 'boleh_menerima' => true, 'boleh_npk' => true, 'boleh_transfer' => true, 'boleh_opname' => true]);
            $this->assertContains((int) $baru, array_map('intval', $gudang->accessibleGudangIds('transfer')));
            $this->assertNotContains((int) $baru, $sebelum);
        }
    }

    public function test_perhitungan_pengisian_ulang_memakai_agregat_bukan_query_per_pengaturan(): void
    {
        if (DB::table('pengaturan_bahan_gudangs')->where('aktif', true)->count() < 2) {
            $this->markTestSkipped('Butuh minimal dua pengaturan bahan gudang aktif.');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ReplenishmentService::class)->calculate();
        $log = collect(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(1, $log->filter(fn ($q) => str_contains($q['query'], 'from `wms_layer_persediaan`') && str_contains($q['query'], 'sum('))->count());
        $this->assertLessThanOrEqual(1, $log->filter(fn ($q) => str_contains($q['query'], 'from `wms_mutasi_stok`') || str_contains($q['query'], 'from `mutasi_stoks`'))->count());
    }
}
