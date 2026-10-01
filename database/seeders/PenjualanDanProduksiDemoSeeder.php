<?php

namespace Database\Seeders;

use App\Models\Bahan;
use App\Models\Bom;
use App\Models\DataPesanan;
use App\Models\FakturPenjualan;
use App\Models\Gudang;
use App\Models\LayerPersediaan;
use App\Models\PemakaianBarang;
use App\Models\Pelanggan;
use App\Models\PesananPenjualan;
use App\Models\StokGudang;
use App\Models\User;
use App\Services\BomService;
use App\Services\CrossDockService;
use App\Services\DataPesananService;
use App\Services\FakturPenjualanService;
use App\Services\PenjualanService;
use App\Services\StokGudangService;
use App\Services\WmsAccountingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class PenjualanDanProduksiDemoSeeder extends Seeder
{
    public function run(): void
    {
        $sales = User::where('type', User::ROLE_PURCHASING)->first();
        $akuntansi = User::where('type', User::ROLE_ACCOUNTING)->first();
        $finance = User::where('type', User::ROLE_FINANCE)->first();
        $produksi = User::where('type', User::ROLE_PRODUCTION)->first() ?? $sales;
        $gudangUser = User::where('type', User::ROLE_WAREHOUSE)->first() ?? $sales;

        if (!$sales || !$akuntansi || !$finance) {
            $this->command?->warn('Seeder penjualan dilewati: pengguna demo belum lengkap.');

            return;
        }

        Auth::setUser($sales);

        $gudang = Gudang::where('kode', 'GDG-UTAMA')->first();

        if (!$gudang) {
            $this->command?->warn('Seeder penjualan dilewati: Gudang Utama tidak ditemukan.');

            return;
        }

        $bahanBerstok = StokGudang::where('gudang_id', $gudang->id)
            ->whereRaw('stok_tersedia - stok_direservasi >= 6')
            ->orderByDesc('stok_tersedia')
            ->get();

        if ($bahanBerstok->count() < 1) {
            $this->command?->warn('Seeder penjualan dilewati: stok demo tidak mencukupi.');

            return;
        }

        $pelangganProduksi = Pelanggan::updateOrCreate(
            ['kode' => 'CUST-001'],
            ['nama' => 'PT Mitra Sejahtera', 'termin_hari' => 30, 'plafon_kredit' => 500000000, 'is_active' => true, 'alamat' => 'Kawasan Industri Pulogadung, Jakarta']
        );

        $pelangganDagang = Pelanggan::updateOrCreate(
            ['kode' => 'CUST-002'],
            ['nama' => 'CV Karya Abadi', 'termin_hari' => 14, 'plafon_kredit' => 150000000, 'is_active' => true, 'alamat' => 'Jl. Raya Bekasi KM 21, Bekasi']
        );

        $this->jalankan('dagang', fn () => $this->skenarioDagangJatuhTempo($pelangganDagang, $gudang, $sales, $akuntansi));
        $this->jalankan('produksi', fn () => $this->skenarioProduksi($pelangganProduksi, $gudang, $bahanBerstok->fresh(), $sales, $produksi, $akuntansi, $finance));
        $this->jalankan('cross dock', fn () => $this->skenarioCrossDock($pelangganDagang, $gudang, $sales, $gudangUser));

        $this->command?->info('Demo penjualan, produksi, BOM, dan cross dock dibuat.');
    }

    private function jalankan(string $nama, callable $skenario): void
    {
        try {
            $skenario();
        } catch (\Throwable $e) {
            $this->command?->warn("Skenario demo {$nama} dilewati: " . $e->getMessage());
        }
    }

    private function skenarioProduksi(
        Pelanggan $pelanggan,
        Gudang $gudang,
        $bahanBerstok,
        User $sales,
        User $produksi,
        User $akuntansi,
        User $finance
    ): void {
        $komponen = $bahanBerstok->take(2);

        if ($komponen->count() < 2) {
            return;
        }

        $produk = Bahan::whereNotIn('id', $komponen->pluck('bahan_id'))->first();

        if (!$produk) {
            return;
        }

        $this->bom($produk, $komponen, $produksi);

        $penjualan = app(PenjualanService::class);

        $pesanan = $penjualan->buatPesanan([
            'tanggal' => today()->subDays(20)->toDateString(),
            'pelanggan_id' => $pelanggan->id,
            'sales_user_id' => $sales->id,
            'gudang_id' => $gudang->id,
            'nomor_po_pelanggan' => 'PO-MITRA-0041',
            'is_ppn' => true,
            'tarif_ppn' => 11,
            'keterangan' => 'Pesanan produksi berdasarkan BOM standar.',
            'details' => [[
                'bahan_id' => $produk->id,
                'jumlah' => 10,
                'harga_satuan' => 145000,
                'satuan' => $produk->satuan,
            ]],
        ], $sales);

        $dataPesanan = app(DataPesananService::class);

        $wo = $dataPesanan->buat($pesanan->details->first(), [
            'tanggal' => today()->subDays(18)->toDateString(),
            'gudang_id' => $gudang->id,
            'jumlah_rencana' => 10,
        ], $produksi);

        $wo = $dataPesanan->rilis($wo);

        $this->npk($wo, $komponen->first(), $produksi, 22);
        $this->npk($wo, $komponen->last(), $produksi, 9);

        $wo = $dataPesanan->selesaikan($wo->fresh(), 10, $produksi);

        $suratJalan = $penjualan->postingSuratJalan(
            $penjualan->buatSuratJalan($pesanan->fresh('details'), [
                'tanggal' => today()->subDays(6)->toDateString(),
                'nomor_kendaraan' => 'B 9911 TXA',
                'pengirim' => 'Ekspedisi Mitra',
                'details' => [[
                    'pesanan_penjualan_detail_id' => $pesanan->details->first()->id,
                    'jumlah' => 10,
                ]],
            ], $sales),
            $sales
        );

        $fakturService = app(FakturPenjualanService::class);

        $faktur = $fakturService->buatDariSuratJalan($suratJalan, [
            'tanggal' => today()->subDays(5)->toDateString(),
            'no_faktur_pajak' => '010.000-26.00000101',
        ], $akuntansi);

        $faktur = $fakturService->posting($faktur, $akuntansi);

        $fakturService->terimaPembayaran($faktur, [
            'tanggal' => today()->subDays(2)->toDateString(),
            'jumlah' => round((float) $faktur->grand_total * 0.4, 2),
            'coa_kas_bank_id' => $this->akunKasBank(),
            'referensi' => 'TRF-MITRA-0041',
        ], $finance);
    }

    private function skenarioDagangJatuhTempo(
        Pelanggan $pelanggan,
        Gudang $gudang,
        User $sales,
        User $akuntansi
    ): void {
        $layer = DB::table('wms_layer_persediaan')
            ->where('gudang_id', $gudang->id)
            ->where('stock_status', 'AVAILABLE')
            ->where('remaining_quantity', '>=', 4)
            ->orderBy('transaction_date')
            ->first();

        if (!$layer) {
            return;
        }

        $tanggal = Carbon::parse($layer->transaction_date)->addDays(3);

        if ($tanggal->isFuture()) {
            $tanggal = today();
        }

        $penjualan = app(PenjualanService::class);

        $pesanan = $penjualan->buatPesanan([
            'tanggal' => $tanggal->toDateString(),
            'pelanggan_id' => $pelanggan->id,
            'sales_user_id' => $sales->id,
            'gudang_id' => $gudang->id,
            'is_ppn' => true,
            'tarif_ppn' => 11,
            'keterangan' => 'Penjualan barang dagang tanpa perintah kerja.',
            'details' => [[
                'bahan_id' => $layer->bahan_id,
                'jumlah' => 4,
                'harga_satuan' => 65000,
            ]],
        ], $sales);

        $suratJalan = $penjualan->postingSuratJalan(
            $penjualan->buatSuratJalan($pesanan->fresh('details'), [
                'tanggal' => $tanggal->toDateString(),
                'details' => [[
                    'pesanan_penjualan_detail_id' => $pesanan->details->first()->id,
                    'jumlah' => 4,
                ]],
            ], $sales),
            $sales
        );

        $fakturService = app(FakturPenjualanService::class);

        $faktur = $fakturService->buatDariSuratJalan($suratJalan, [
            'tanggal' => $tanggal->toDateString(),
            'jatuh_tempo' => $tanggal->copy()->addDays(14)->toDateString(),
            'no_faktur_pajak' => '010.000-26.00000102',
        ], $akuntansi);

        $fakturService->posting($faktur, $akuntansi);
    }

    private function skenarioCrossDock(Pelanggan $pelanggan, Gudang $gudang, User $sales, User $operator): void
    {
        $penerimaan = DB::table('wms_penerimaan_barang_detail as d')
            ->join('wms_penerimaan_barang as l', 'l.id_lpb', '=', 'd.id_lpb')
            ->where('l.document_type', 'GOODS')
            ->where('l.status', 'POSTED')
            ->where('l.gudang_id', $gudang->id)
            ->select('d.id_bahan', 'd.jumlah_barang_diterima')
            ->orderByDesc('d.id')
            ->first();

        if (!$penerimaan) {
            return;
        }

        DB::table('wms_penerimaan_barang')
            ->where('gudang_id', $gudang->id)
            ->where('status', 'POSTED')
            ->update(['tanggal' => today()->subDays(3)]);

        $jumlah = min(2, (float) $penerimaan->jumlah_barang_diterima);

        if ($jumlah <= 0) {
            return;
        }

        $pesanan = app(PenjualanService::class)->buatPesanan([
            'tanggal' => today()->toDateString(),
            'pelanggan_id' => $pelanggan->id,
            'sales_user_id' => $sales->id,
            'gudang_id' => $gudang->id,
            'is_ppn' => true,
            'tarif_ppn' => 11,
            'keterangan' => 'Menunggu barang masuk untuk cross dock.',
            'details' => [[
                'bahan_id' => $penerimaan->id_bahan,
                'jumlah' => $jumlah,
                'harga_satuan' => 72000,
            ]],
        ], $sales);

        $saran = app(CrossDockService::class)->saran($gudang->id);
        $cocok = $saran->firstWhere('pesanan_penjualan_detail_id', $pesanan->details->first()->id);

        if (!$cocok) {
            return;
        }

        try {
            app(CrossDockService::class)->tandai([
                'penerimaan_barang_detail_id' => $cocok['penerimaan_barang_detail_id'],
                'pesanan_penjualan_detail_id' => $cocok['pesanan_penjualan_detail_id'],
                'jumlah' => $cocok['usul'],
                'keterangan' => 'Dikunci untuk pengiriman langsung dari area terima.',
            ], $operator);
        } catch (RuntimeException $e) {
            $this->command?->warn('Cross dock demo dilewati: ' . $e->getMessage());
        }
    }

    private function bom(Bahan $produk, $komponen, User $user): Bom
    {
        $existing = Bom::where('bahan_id', $produk->id)->first();

        if ($existing) {
            return $existing;
        }

        return app(BomService::class)->buat([
            'kode' => 'BOM-' . strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $produk->nama), 0, 8)),
            'nama' => 'BOM standar ' . $produk->nama,
            'bahan_id' => $produk->id,
            'versi' => '1',
            'jumlah_hasil' => 1,
            'catatan' => 'Standar pemakaian per satu unit hasil.',
            'details' => $komponen->values()->map(fn ($stok, $index) => [
                'bahan_id' => $stok->bahan_id,
                'jumlah' => $index === 0 ? 2 : 1,
                'satuan' => Bahan::find($stok->bahan_id)?->satuan,
            ])->all(),
        ], $user);
    }

    private function npk(DataPesanan $wo, StokGudang $stok, User $user, float $jumlah): void
    {
        $bahan = Bahan::find($stok->bahan_id);

        if (!$bahan) {
            return;
        }

        $tersedia = (float) $stok->fresh()->stok_tersedia - (float) $stok->fresh()->stok_direservasi;
        $jumlah = min($jumlah, max($tersedia - 1, 0));

        if ($jumlah <= 0) {
            return;
        }

        $npk = PemakaianBarang::create([
            'kode' => app(\App\Services\DocumentNumberService::class)->internal('NPK', 'PRD'),
            'kode_datapesanan' => $wo->nomor,
            'data_pesanan_id' => $wo->id,
            'tanggal' => $this->tanggalPemakaian((int) $stok->gudang_id, (int) $bahan->id, $bahan->toStockQuantity($jumlah)),
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
            (int) $stok->gudang_id,
            (int) $bahan->id,
            (float) $npk->jumlah_stok,
            (float) $npk->fresh()->harga_satuan,
            'PENGELUARAN',
            'NPK',
            $npk->id,
            $npk->kode
        );

        app(WmsAccountingService::class)->postNpk($npk->fresh());
    }

    private function tanggalPemakaian(int $gudangId, int $bahanId, float $jumlahStok): string
    {
        $bawaan = today()->subDays(15);

        $layers = LayerPersediaan::where('gudang_id', $gudangId)
            ->where('bahan_id', $bahanId)
            ->where('stock_status', 'AVAILABLE')
            ->where('remaining_quantity', '>', 0)
            ->orderBy('transaction_date')
            ->get(['transaction_date', 'remaining_quantity']);

        $terkumpul = 0.0;

        foreach ($layers as $layer) {
            $terkumpul += (float) $layer->remaining_quantity;

            if ($terkumpul + 0.000001 >= $jumlahStok) {
                $tanggalLayer = \Illuminate\Support\Carbon::parse($layer->transaction_date);

                return $tanggalLayer->greaterThan($bawaan) ? $tanggalLayer->toDateString() : $bawaan->toDateString();
            }
        }

        return $bawaan->toDateString();
    }

    private function akunKasBank(): int
    {
        return (int) \App\Models\BaganAkun::whereIn('kode_akun', ['1102', '1101'])
            ->orderByDesc('kode_akun')
            ->value('id');
    }
}
