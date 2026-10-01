<?php

namespace Database\Seeders;

use App\Models\AccountingPeriodLock;
use App\Models\BaganAkun;
use App\Models\Gudang;
use App\Models\Kit;
use App\Models\PermintaanPersetujuan;
use App\Models\StokGudang;
use App\Models\SuratJalan;
use App\Models\FakturPenjualan;
use App\Services\FakturPenjualanService;
use App\Models\User;
use App\Services\CalkService;
use App\Services\KittingService;
use App\Services\PersetujuanOperasiService;
use App\Services\ReturPenjualanService;
use App\Services\WarehouseExecutionService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Throwable;

class EksekusiGudangDemoSeeder extends Seeder
{
    public function run(): void
    {
        $gudangUser = User::where('type', User::ROLE_WAREHOUSE)->first();
        $akuntansiUser = User::where('type', User::ROLE_ACCOUNTING)->first();
        $manajerUser = User::where('type', User::ROLE_ACCOUNTING_MANAGER)->first() ?? $akuntansiUser;
        $salesUser = User::where('type', User::ROLE_PURCHASING)->first();

        if (!$gudangUser || !$akuntansiUser || !$salesUser) {
            $this->command?->warn('Seeder eksekusi gudang dilewati: pengguna demo belum lengkap.');

            return;
        }

        $this->jalankan('Kitting', fn () => $this->kitting($gudangUser));
        $this->jalankan('Reservasi dan pengambilan', fn () => $this->pengambilan($gudangUser));
        $this->jalankan('Retur penjualan', fn () => $this->returPenjualan($salesUser));
        $this->jalankan('Uang muka pelanggan', fn () => $this->uangMukaPelanggan($salesUser));
        $this->jalankan('Periode terkunci', fn () => $this->periodeTerkunci($akuntansiUser));
        $this->jalankan('Catatan laporan keuangan', fn () => $this->calk($akuntansiUser));
        $this->jalankan('Permintaan persetujuan', fn () => $this->permintaanPersetujuan($akuntansiUser, $manajerUser));
    }

    private function uangMukaPelanggan(User $user): void
    {
        Auth::setUser($user);

        $faktur = FakturPenjualan::whereIn('status', [FakturPenjualan::POSTED, FakturPenjualan::PARTIALLY_PAID])
            ->where('sisa_tagihan', '>', 0)
            ->orderBy('tanggal')
            ->first();

        if (!$faktur) {
            throw new RuntimeException('tidak ada faktur penjualan bersisa tagihan untuk menerima kelebihan bayar.');
        }

        $kas = BaganAkun::where('is_cash_bank', true)->where('is_active', true)->orderBy('kode_akun')->first();

        if (!$kas) {
            throw new RuntimeException('belum ada akun kas/bank untuk menerima pembayaran.');
        }

        $kelebihan = 250000;

        app(FakturPenjualanService::class)->terimaPembayaran($faktur, [
            'tanggal' => today()->subDay()->toDateString(),
            'coa_kas_bank_id' => $kas->id,
            'jumlah' => round((float) $faktur->sisa_tagihan + $kelebihan, 2),
            'referensi' => 'Transfer pelanggan melebihi tagihan',
        ], $user);

        $this->command?->info("Uang muka pelanggan: faktur {$faktur->nomor} dilunasi dan kelebihan Rp" . number_format($kelebihan, 0, ',', '.') . ' diparkir sebagai uang muka.');
    }

    private function jalankan(string $label, callable $skenario): void
    {
        try {
            $skenario();
        } catch (Throwable $e) {
            $this->command?->warn("Skenario {$label} dilewati: " . $e->getMessage());
        }
    }

    private function gudangUtama(): Gudang
    {
        return Gudang::where('kode', 'GDG-UTAMA')->firstOrFail();
    }

    private function kitting(User $user): void
    {
        Auth::setUser($user);

        $gudang = $this->gudangUtama();

        $stok = StokGudang::where('gudang_id', $gudang->id)
            ->whereRaw('stok_tersedia - stok_direservasi >= 3')
            ->orderByDesc('stok_tersedia')
            ->get();

        if ($stok->count() < 3) {
            throw new RuntimeException('butuh tiga bahan berstok untuk membentuk kit demo.');
        }

        $kit = Kit::updateOrCreate(
            ['kode' => 'KIT-PAKET-AUDIT'],
            [
                'nama' => 'Paket Perlengkapan Audit',
                'bahan_hasil_id' => $stok[0]->bahan_id,
                'jumlah_hasil' => 1,
                'aktif' => true,
                'catatan' => 'Satu paket berisi bahan pendukung audit lapangan.',
            ]
        );

        $kit->komponen()->delete();
        $kit->komponen()->createMany([
            ['bahan_id' => $stok[1]->bahan_id, 'jumlah' => 2],
            ['bahan_id' => $stok[2]->bahan_id, 'jumlah' => 1],
        ]);

        $service = app(KittingService::class);
        $service->rakit($kit->fresh('komponen'), $gudang->id, 2, now()->subDays(8)->toDateString(), 'Perakitan paket audit untuk stok siap kirim.');
        $service->urai($kit->fresh('komponen'), $gudang->id, 1, now()->subDays(3)->toDateString(), 'Satu paket diurai kembali karena salah komposisi.');

        $this->command?->info('Kitting: satu kit didefinisikan, dua dirakit, satu diurai kembali.');
    }

    private function pengambilan(User $user): void
    {
        Auth::setUser($user);

        $gudang = $this->gudangUtama();

        $stok = StokGudang::where('gudang_id', $gudang->id)
            ->whereRaw('stok_tersedia - stok_direservasi >= 2')
            ->orderByDesc('stok_tersedia')
            ->first();

        if (!$stok) {
            throw new RuntimeException('tidak ada stok bebas untuk direservasi.');
        }

        $service = app(WarehouseExecutionService::class);

        $selesai = $service->reserve($gudang->id, $stok->bahan_id, 1, 'DEMO_PENGAMBILAN', null, $user->id);
        $pick = $service->createPick($selesai);
        $service->completePick($pick);
        $service->createDraftIssue($pick->fresh());

        $service->reserve($gudang->id, $stok->bahan_id, 1, 'DEMO_RESERVASI', null, $user->id);

        $this->command?->info('Pengambilan: satu reservasi dipetik sampai menjadi NPK draft, satu reservasi dibiarkan terbuka.');
    }

    private function returPenjualan(User $user): void
    {
        Auth::setUser($user);

        $suratJalan = SuratJalan::with('details')
            ->whereHas('details')
            ->get()
            ->first(fn (SuratJalan $sj) => $sj->isPosted());

        if (!$suratJalan) {
            throw new RuntimeException('belum ada surat jalan terposting yang bisa diretur.');
        }

        $detail = $suratJalan->details->first(fn ($d) => (float) $d->jumlah > 1);

        if (!$detail) {
            throw new RuntimeException('tidak ada baris surat jalan dengan jumlah lebih dari satu.');
        }

        app(ReturPenjualanService::class)->buat($suratJalan, [
            'tanggal' => now()->subDays(2)->toDateString(),
            'alasan' => 'Satu unit diterima dalam kondisi tidak sesuai spesifikasi.',
            'details' => [['surat_jalan_detail_id' => $detail->id, 'jumlah' => 1]],
        ], $user);

        $this->command?->info('Retur penjualan: satu unit dikembalikan pelanggan dan masuk kembali sebagai layer baru.');
    }

    private function periodeTerkunci(User $user): void
    {
        $awal = now()->subMonthsNoOverflow(6)->startOfMonth();

        AccountingPeriodLock::updateOrCreate(
            ['period_start' => $awal->toDateString()],
            [
                'period_end' => $awal->copy()->endOfMonth()->toDateString(),
                'status' => 'LOCKED',
                'reason' => 'Periode sudah dilaporkan ke manajemen dan tidak boleh diubah.',
                'locked_by' => $user->id,
                'locked_by_name' => $user->name,
                'locked_at' => now()->subMonthsNoOverflow(5),
            ]
        );

        $this->command?->info("Periode terkunci: {$awal->format('Y-m')} dikunci sehingga posting mundur ke sana akan ditolak.");
    }

    private function calk(User $user): void
    {
        $dari = now()->subYear()->startOfYear();
        $sampai = now()->subYear()->endOfYear();

        app(CalkService::class)->simpan($dari, $sampai, [
            'dasar_penyusunan' => 'Laporan keuangan disusun atas dasar akrual dengan konsep biaya historis, kecuali pos moneter dalam mata uang asing yang dijabarkan memakai kurs penutup sesuai PSAK 10.',
            'kebijakan_akuntansi' => 'Persediaan dinilai memakai metode masuk pertama keluar pertama per gudang. Aset tetap disusutkan garis lurus dan saldo menurun ganda sesuai kelompok aset. Pajak tangguhan diakui memakai metode liabilitas neraca sesuai PSAK 46.',
            'pertimbangan_signifikan' => 'Manajemen menilai tidak terdapat indikasi penurunan nilai persediaan maupun piutang pada periode berjalan. Selisih transfer antargudang yang belum tuntas tetap disajikan pada akun Persediaan Dalam Perjalanan sampai penyelesaiannya diputuskan.',
            'peristiwa_setelah_periode' => 'Tidak terdapat peristiwa setelah periode pelaporan yang berdampak material terhadap laporan keuangan.',
        ], $user->id);

        $this->command?->info("CALK: empat bagian naratif diisi untuk tahun buku {$dari->year}.");
    }

    private function permintaanPersetujuan(User $pemohon, User $pemutus): void
    {
        $service = app(PersetujuanOperasiService::class);

        $akun = BaganAkun::where('is_postable', true)->orderBy('kode_akun')->first();

        if (!$akun) {
            throw new RuntimeException('bagan akun belum terisi.');
        }

        $disetujui = $service->ajukan(
            PermintaanPersetujuan::PERUBAHAN_COA,
            PersetujuanOperasiService::COA_UBAH,
            "Perbarui keterangan akun {$akun->kode_akun}",
            ['keterangan' => 'Akun kas operasional harian'],
            'Keterangan lama tidak lagi menggambarkan penggunaan akun.',
            $pemohon,
            $akun,
        );

        if ($pemutus->id !== $pemohon->id) {
            $service->setujui($disetujui, $pemutus, 'Perubahan wajar dan tidak mengubah saldo.');
        }

        $akunLain = BaganAkun::where('is_postable', true)->where('id', '!=', $akun->id)->orderBy('kode_akun')->first();

        if ($akunLain) {
            $service->ajukan(
                PermintaanPersetujuan::PERUBAHAN_COA,
                PersetujuanOperasiService::COA_UBAH,
                "Perbarui keterangan akun {$akunLain->kode_akun}",
                ['keterangan' => 'Menunggu telaah manajer akuntansi'],
                'Usulan penyeragaman penamaan akun.',
                $pemohon,
                $akunLain,
            );
        }

        $this->command?->info('Persetujuan: satu permintaan disetujui dan satu dibiarkan menunggu keputusan.');
    }
}
