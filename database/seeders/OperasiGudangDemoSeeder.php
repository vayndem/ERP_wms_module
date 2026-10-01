<?php

namespace Database\Seeders;

use App\Models\Bom;
use App\Models\DetailTransferGudang;
use App\Models\FakturPembelian;
use App\Models\Gudang;
use App\Models\PusatKerja;
use App\Models\StokGudang;
use App\Models\Supplier;
use App\Models\TransferGudang;
use App\Models\AccountingSetting;
use App\Models\BiayaTambahan;
use App\Models\LayerPersediaan;
use App\Services\LandedCostService;
use App\Models\DataPesanan;
use App\Services\SerapanProduksiService;
use App\Models\PesananPenjualan;
use App\Models\PesananPenjualanDetail;
use App\Services\DataPesananService;
use App\Services\SubkontrakService;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\PengeluaranBarangService;
use App\Services\RevaluasiKursService;
use App\Services\RoutingProduksiService;
use App\Services\TransferGudangService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class OperasiGudangDemoSeeder extends Seeder
{
    public function run(): void
    {
        $gudangUser = User::where('type', User::ROLE_WAREHOUSE)->first();
        $produksiUser = User::where('type', User::ROLE_PRODUCTION)->first() ?? $gudangUser;
        $akuntansiUser = User::where('type', User::ROLE_ACCOUNTING)->first();

        if (!$gudangUser || !$akuntansiUser) {
            $this->command?->warn('Seeder operasi gudang dilewati: pengguna demo belum lengkap.');

            return;
        }

        $this->jalankan('Transfer gudang', fn () => $this->transferGudang($gudangUser));
        $this->jalankan('Barang keluar dan titipan', fn () => $this->kustodiBarang($gudangUser));
        $this->jalankan('Routing produksi', fn () => $this->routingProduksi($produksiUser));
        $this->jalankan('Revaluasi kurs', fn () => $this->revaluasiKurs($akuntansiUser));
        $this->jalankan('Landed cost', fn () => $this->landedCost($akuntansiUser));
        $this->jalankan('Serapan jam kerja', fn () => $this->serapanJamKerja($produksiUser));
        $this->jalankan('Pengiriman subkontrak', fn () => $this->subkontrak($gudangUser));
    }

    private function landedCost(User $user): void
    {
        Auth::setUser($user);

        $layer = LayerPersediaan::where('stock_status', 'AVAILABLE')
            ->where('remaining_quantity', '>', 0)
            ->orderBy('id')
            ->first();

        if (!$layer) {
            throw new RuntimeException('tidak ada layer aktif untuk dibebani biaya tambahan.');
        }

        if (BiayaTambahan::where('status', 'POSTED')->exists()) {
            return;
        }

        $biaya = BiayaTambahan::create([
            'number' => app(DocumentNumberService::class)->internal('BTB', 'LC'),
            'date' => today()->subDays(3)->toDateString(),
            'description' => 'Ongkos angkut dan bongkar muat pengiriman supplier',
            'total_amount' => 150000,
            'allocation_basis' => 'VALUE',
            'credit_coa_id' => AccountingSetting::accountId(AccountingSetting::BIAYA_MASIH_HARUS_DIBAYAR),
            'status' => 'DRAFT',
            'created_by' => $user->id,
        ]);

        $service = app(LandedCostService::class);
        $service->allocate($biaya, [$layer->id]);
        $service->post($biaya);

        $this->command?->info('Landed cost: ongkos angkut dikapitalisasi ke layer persediaan dan dibebankan ke Biaya Masih Harus Dibayar.');
    }

    private function serapanJamKerja(User $user): void
    {
        Auth::setUser($user);

        $pusat = PusatKerja::where('status', PusatKerja::AKTIF)->orderBy('kode')->get();

        if ($pusat->isEmpty()) {
            throw new RuntimeException('belum ada pusat kerja untuk diberi tarif.');
        }

        foreach ($pusat as $index => $satu) {
            $satu->update([
                'tarif_tenaga_kerja_per_jam' => [45000, 60000, 35000][$index % 3],
                'tarif_overhead_per_jam' => [15000, 25000, 12000][$index % 3],
            ]);
        }

        $pesanan = DataPesanan::berjalan()->orderBy('id')->first() ?? $this->perintahKerjaBerjalan($user);

        if (!$pesanan) {
            throw new RuntimeException('tidak ada baris pesanan penjualan yang bisa dijadikan perintah kerja berjalan.');
        }

        $service = app(SerapanProduksiService::class);
        $service->catat($pesanan, $pusat->first(), ['tanggal' => today()->subDays(4)->toDateString(), 'jam' => 6, 'keterangan' => 'Potong dan jahit'], $user);
        $service->catat($pesanan, $pusat->last(), ['tanggal' => today()->subDays(2)->toDateString(), 'jam' => 3.5, 'keterangan' => 'Finishing dan QC produksi'], $user);

        $this->command?->info('Serapan jam kerja: tarif pusat kerja diisi dan dua catatan jam diserap ke barang dalam proses.');
    }

    private function perintahKerjaBerjalan(User $user): ?DataPesanan
    {
        $detail = PesananPenjualanDetail::whereHas('pesanan', fn ($query) => $query->where('status', PesananPenjualan::OPEN))
            ->whereNotIn('id', DataPesanan::whereNotNull('pesanan_penjualan_detail_id')->pluck('pesanan_penjualan_detail_id'))
            ->orderByDesc('id')
            ->first();

        if (!$detail) {
            return null;
        }

        $service = app(DataPesananService::class);

        $pesanan = $service->buat($detail, [
            'tanggal' => today()->subDays(5)->toDateString(),
            'jumlah_rencana' => max(1, (float) $detail->jumlah),
            'keterangan' => 'Perintah kerja berjalan untuk demo serapan jam kerja.',
        ], $user);

        return $service->rilis($pesanan);
    }

    private function subkontrak(User $user): void
    {
        Auth::setUser($user);

        $service = app(SubkontrakService::class);
        $gudangSubkontrak = $service->gudangSubkontrak();

        $asal = Gudang::where('kode', 'GDG-UTAMA')->firstOrFail();
        $tanggal = now()->subDays(10);
        $dilindungi = $this->bahanPenopangReturPascaInvoice();
        $bahanId = $this->bahanSiapTransfer($asal, $tanggal, 1)
            ->reject(fn ($id) => $dilindungi->contains($id))
            ->first();

        if (!$bahanId) {
            throw new RuntimeException('tidak ada bahan bebas yang layernya cukup tua untuk dikirim ke vendor tanpa menggerus fixture retur pasca-invoice.');
        }

        $supplier = Supplier::orderBy('id')->first();

        if (!$supplier) {
            throw new RuntimeException('belum ada supplier yang bisa dijadikan vendor subkontrak.');
        }

        $service->kirim([
            'tanggal' => $tanggal->toDateString(),
            'supplier_id' => $supplier->id,
            'gudang_asal_id' => $asal->id,
            'estimasi_kembali' => today()->subDays(3)->toDateString(),
            'keperluan' => 'Jasa bordir dan finishing',
            'details' => [['bahan_id' => $bahanId, 'jumlah' => 1]],
        ], $user);

        $this->command?->info("Subkontrak: satu bahan dikirim ke {$supplier->nama} dan masih di vendor melewati estimasi kembali ({$gudangSubkontrak->nama}).");
    }

    private function jalankan(string $label, callable $skenario): void
    {
        try {
            $skenario();
        } catch (Throwable $e) {
            $this->command?->warn("Skenario {$label} dilewati: " . $e->getMessage());
        }
    }

    private function transferGudang(User $user): void
    {
        Auth::setUser($user);

        $asal = Gudang::where('kode', 'GDG-UTAMA')->firstOrFail();
        $tujuan = Gudang::where('kode', 'GDG-PRODUKSI')->firstOrFail();

        $tanggalPertama = now()->subDays(12);
        $tersedia = $this->bahanSiapTransfer($asal, $tanggalPertama, 2);

        if ($tersedia->count() < 2) {
            throw new RuntimeException('tidak ada dua bahan yang layernya cukup tua untuk tiga skenario transfer.');
        }

        $service = app(TransferGudangService::class);

        $diterima = $this->buatTransfer($asal, $tujuan, $tersedia[0], 1, $tanggalPertama, $user);
        $service->konfirmasi($diterima);
        $service->terima($diterima->fresh(), [], 'Diterima lengkap sesuai surat jalan.');

        $kurang = $this->buatTransfer($asal, $tujuan, $tersedia[0], 2, now()->subDays(9), $user);
        $service->konfirmasi($kurang);
        $detailKurang = $kurang->fresh('details')->details->first();
        $service->terima($kurang->fresh(), [$detailKurang->id => 1], 'Satu unit tidak sampai, dicatat sebagai selisih transfer.');

        $perjalanan = $this->buatTransfer($asal, $tujuan, $tersedia[1], 1, now()->subDays(6), $user);
        $service->konfirmasi($perjalanan);

        $this->command?->info('Transfer gudang: satu diterima lengkap, satu kurang terima, satu masih dalam perjalanan.');
    }

    private function bahanSiapTransfer(Gudang $gudang, $tanggal, float $minimal): Collection
    {
        $reservasi = StokGudang::where('gudang_id', $gudang->id)->pluck('stok_direservasi', 'bahan_id');

        $kandidat = LayerPersediaan::where('gudang_id', $gudang->id)
            ->where('stock_status', 'AVAILABLE')
            ->where('remaining_quantity', '>', 0)
            ->whereDate('transaction_date', '<=', $tanggal)
            ->selectRaw('bahan_id, SUM(remaining_quantity) as tersedia')
            ->groupBy('bahan_id')
            ->orderByDesc('tersedia')
            ->get()
            ->filter(fn ($baris) => (float) $baris->tersedia - (float) ($reservasi[$baris->bahan_id] ?? 0) >= $minimal)
            ->pluck('bahan_id')
            ->values();

        $dilindungi = $this->bahanPenopangReturPascaInvoice();
        $aman = $kandidat->reject(fn ($bahanId) => $dilindungi->contains($bahanId))->values();

        return $aman->count() >= 2 ? $aman : $kandidat;
    }

    private function bahanPenopangReturPascaInvoice(): Collection
    {
        return DB::table('wms_layer_persediaan as l')
            ->join('wms_penerimaan_barang_detail as d', 'd.id', '=', 'l.source_id')
            ->join('wms_penerimaan_barang as lpb', 'lpb.id_lpb', '=', 'd.id_lpb')
            ->join('wms_faktur_pembelian as f', 'f.no_invoice', '=', 'lpb.no_invoice')
            ->where('l.source_type', 'LPB_DETAIL')
            ->where('l.remaining_quantity', '>', 0)
            ->where('f.sisa_tagihan', '>', 0)
            ->distinct()
            ->pluck('l.bahan_id');
    }

    private function buatTransfer(Gudang $asal, Gudang $tujuan, int $bahanId, float $jumlah, $tanggal, User $user): TransferGudang
    {
        $transfer = TransferGudang::create([
            'nomor_transfer' => app(DocumentNumberService::class)->internal('TRF', 'GDG'),
            'tanggal' => $tanggal->toDateString(),
            'gudang_asal_id' => $asal->id,
            'gudang_tujuan_id' => $tujuan->id,
            'status' => TransferGudang::DRAFT,
            'keterangan' => 'Pemindahan bahan ke gudang produksi',
            'dibuat_oleh' => $user->id,
        ]);

        DetailTransferGudang::create([
            'transfer_gudang_id' => $transfer->id,
            'bahan_id' => $bahanId,
            'jumlah' => $jumlah,
        ]);

        $transfer->update([
            'status' => TransferGudang::DIAJUKAN,
            'diajukan_oleh' => $user->id,
            'diajukan_pada' => $tanggal,
        ]);

        return $transfer->fresh('details');
    }

    private function kustodiBarang(User $user): void
    {
        Auth::setUser($user);

        $supplier = Supplier::firstOrFail();
        $service = app(PengeluaranBarangService::class);

        $terlambat = $service->buat([
            'tanggal' => now()->subDays(25)->toDateString(),
            'supplier_id' => $supplier->id,
            'keperluan' => 'PERBAIKAN',
            'estimasi_kembali' => now()->subDays(5)->toDateString(),
            'catatan' => 'Servis besar, vendor belum mengembalikan.',
            'items' => [['deskripsi' => 'Mesin potong semi otomatis', 'nomor_seri' => 'MSN-0142', 'jumlah' => 1, 'satuan' => 'UNIT']],
        ]);
        $service->kirim($terlambat);

        $berjalan = $service->buat([
            'tanggal' => now()->subDays(4)->toDateString(),
            'supplier_id' => $supplier->id,
            'keperluan' => 'KALIBRASI',
            'estimasi_kembali' => now()->addDays(10)->toDateString(),
            'items' => [['deskripsi' => 'Timbangan lantai 500 kg', 'nomor_seri' => 'TMB-0007', 'jumlah' => 1, 'satuan' => 'UNIT']],
        ]);
        $service->kirim($berjalan);

        $selesai = $service->buat([
            'tanggal' => now()->subDays(30)->toDateString(),
            'supplier_id' => $supplier->id,
            'keperluan' => 'PERBAIKAN',
            'estimasi_kembali' => now()->subDays(18)->toDateString(),
            'items' => [['deskripsi' => 'Forklift baterai', 'nomor_seri' => 'FKL-0003', 'jumlah' => 1, 'satuan' => 'UNIT']],
        ]);
        $service->kirim($selesai);
        $service->terimaKembali(
            $selesai->fresh('details'),
            $selesai->fresh('details')->details->map(fn ($d) => ['id' => $d->id, 'kondisi_kembali' => 'Baik, sudah diservis'])->all(),
            now()->subDays(16)->toDateString()
        );

        $service->terimaTitipan([
            'supplier_id' => $supplier->id,
            'deskripsi' => 'Alat uji kekerasan pinjaman vendor',
            'nomor_seri' => 'UJI-2210',
            'jumlah' => 1,
            'satuan' => 'UNIT',
            'tanggal_terima' => now()->subDays(40)->toDateString(),
            'estimasi_kembali' => now()->subDays(6)->toDateString(),
            'nilai_taksiran' => 12500000,
            'catatan' => 'Milik vendor, tidak dibukukan sebagai aset maupun persediaan.',
        ]);

        $this->command?->info('Kustodi barang: tiga gate pass dan satu barang titipan dibuat.');
    }

    private function routingProduksi(User $user): void
    {
        Auth::setUser($user);

        $service = app(RoutingProduksiService::class);

        $pusat = collect([
            ['kode' => 'WC-POTONG', 'nama' => 'Pemotongan', 'kapasitas_menit_per_hari' => 480, 'keterangan' => 'Dua mesin potong, satu shift'],
            ['kode' => 'WC-RAKIT', 'nama' => 'Perakitan', 'kapasitas_menit_per_hari' => 960, 'keterangan' => 'Dua shift'],
            ['kode' => 'WC-FINISH', 'nama' => 'Finishing dan QC', 'kapasitas_menit_per_hari' => 480],
        ])->map(fn ($data) => PusatKerja::where('kode', $data['kode'])->first() ?? $service->simpanPusatKerja($data, $user));

        $bom = Bom::where('status', Bom::AKTIF)->first();

        if (!$bom) {
            throw new RuntimeException('belum ada BOM aktif untuk dipasangi routing.');
        }

        $service->simpanOperasi($bom, [
            ['pusat_kerja_id' => $pusat[0]->id, 'urutan' => 1, 'nama_operasi' => 'Potong bahan sesuai ukuran', 'waktu_standar_menit' => 12],
            ['pusat_kerja_id' => $pusat[1]->id, 'urutan' => 2, 'nama_operasi' => 'Rakit komponen', 'waktu_standar_menit' => 35],
            ['pusat_kerja_id' => $pusat[2]->id, 'urutan' => 3, 'nama_operasi' => 'Finishing dan pemeriksaan akhir', 'waktu_standar_menit' => 18],
        ]);

        $this->command?->info('Routing produksi: tiga pusat kerja dan tiga operasi dipasang pada BOM aktif.');
    }

    private function revaluasiKurs(User $user): void
    {
        Auth::setUser($user);

        $faktur = FakturPembelian::whereNotIn('status', [FakturPembelian::VOID, FakturPembelian::PENDING_APPROVAL])
            ->where('sisa_tagihan', '>', 0)
            ->orderByDesc('sisa_tagihan')
            ->first();

        if (!$faktur) {
            throw new RuntimeException('tidak ada tagihan supplier terbuka untuk dijadikan tagihan valas.');
        }

        $kursTransaksi = 15200;

        $faktur->forceFill([
            'mata_uang_asing' => 'USD',
            'kurs' => $kursTransaksi,
            'nilai_asing' => round((float) $faktur->grand_total / $kursTransaksi, 2),
        ])->save();

        $service = app(RevaluasiKursService::class);
        $periode = now()->subMonthNoOverflow()->format('Y-m');

        $service->simpanKurs(['mata_uang' => 'USD', 'periode' => $periode, 'kurs' => 15750, 'sumber' => 'Kurs tengah Bank Indonesia'], $user);
        $service->posting($periode, $user);

        $this->command?->info("Revaluasi kurs: periode {$periode} diposting atas satu tagihan USD.");
    }
}
