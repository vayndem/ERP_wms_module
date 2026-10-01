<?php

namespace Tests\Feature;

use App\Services\AccountingReconciliationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KekayaanDataDemoTest extends TestCase
{
    use DatabaseTransactions;

    public static function tabelYangHarusTerisi(): array
    {
        return [
            'transfer gudang' => ['transfer_gudangs'],
            'detail transfer gudang' => ['detail_transfer_gudangs'],
            'alokasi transfer gudang' => ['alokasi_transfer_gudangs'],
            'barang keluar' => ['wms_pengeluaran_barang'],
            'detail barang keluar' => ['wms_pengeluaran_barang_detail'],
            'barang titipan' => ['wms_barang_titipan'],
            'pusat kerja' => ['wms_pusat_kerja'],
            'jam kerja produksi' => ['wms_jam_kerja_produksi'],
            'uang muka pelanggan' => ['wms_uang_muka_pelanggan'],
            'pengiriman subkontrak' => ['wms_pengiriman_subkontrak'],
            'detail pengiriman subkontrak' => ['wms_pengiriman_subkontrak_detail'],
            'biaya tambahan (landed cost)' => ['wms_biaya_tambahan'],
            'operasi bom' => ['wms_bom_operasi'],
            'kurs penutup' => ['wms_kurs_penutup'],
            'revaluasi kurs' => ['wms_revaluasi_kurs'],
            'kit' => ['wms_kit'],
            'komponen kit' => ['wms_kit_komponen'],
            'perakitan kit' => ['wms_perakitan_kit'],
            'pesanan pengambilan' => ['wms_pesanan_pengambilan'],
            'gelombang pengambilan' => ['wms_gelombang_pengambilan'],
            'retur penjualan' => ['wms_retur_penjualan'],
            'pemeriksaan kualitas' => ['wms_pemeriksaan_kualitas'],
            'nomor seri' => ['wms_serial_persediaan'],
            'pembalikan dokumen' => ['wms_pembalikan_dokumen'],
            'permintaan persetujuan' => ['wms_permintaan_persetujuan'],
            'periode terkunci' => ['accounting_period_locks'],
            'catatan laporan keuangan' => ['wms_catatan_laporan_keuangan'],
            'saran pengisian ulang' => ['wms_saran_pengisian_ulang'],
            'perhitungan pajak penghasilan' => ['wms_perhitungan_pajak_penghasilan'],
        ];
    }

    /**
     * @dataProvider tabelYangHarusTerisi
     */
    public function test_the_demo_dataset_exercises_every_major_document_type(string $tabel): void
    {
        $this->assertGreaterThan(
            0,
            DB::table($tabel)->count(),
            "Tabel {$tabel} kosong di data demo. Tabel yang tidak pernah terisi berarti jalur kodenya tidak pernah "
                . 'dijalankan seeder, dan itulah cara cacat seperti tabel hantu riwayat pesanan pembelian bertahan lama.'
        );
    }

    public function test_the_enriched_dataset_keeps_every_invariant_valid(): void
    {
        $invarian = app(AccountingReconciliationService::class)->checks();

        $menyimpang = $invarian->where('invalid', '>', 0)->pluck('key')->implode(', ');

        $this->assertSame(
            '',
            $menyimpang,
            "Invarian berikut menyimpang pada data demo: {$menyimpang}. Menambah skenario seeder tidak boleh "
                . 'mengorbankan keterhubungan buku besar dengan subledgernya.'
        );

        $this->assertSame(8, $invarian->count(), 'Jumlah invarian berubah tanpa catatan.');
    }

    public function test_the_demo_dataset_carries_both_halves_of_a_two_phase_transfer(): void
    {
        $this->assertGreaterThan(
            0,
            DB::table('transfer_gudangs')->where('status', 'DITERIMA')->count(),
            'Perlu satu transfer yang sudah diterima agar jurnal dua tahap lengkap terwakili.'
        );

        $this->assertGreaterThan(
            0,
            DB::table('wms_layer_persediaan')->where('stock_status', 'IN_TRANSIT')->count(),
            'Perlu stok yang masih dalam perjalanan agar akun 1304 dan invarian transit punya data nyata.'
        );

        $this->assertGreaterThan(
            0,
            DB::table('detail_transfer_gudangs')->where('jumlah_selisih', '>', 0)->count(),
            'Perlu satu transfer kurang terima agar jalur TRANSFER_SHORTAGE benar-benar dijalankan.'
        );
    }
}
