<?php

namespace App\Services;

use App\Models\FakturPenjualan;
use App\Models\PenerimaanPembayaran;
use App\Models\UangMukaPelanggan;
use App\Models\SuratJalan;
use App\Models\SuratJalanDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FakturPenjualanService
{
    public function __construct(
        private WmsAccountingService $akuntansi,
        private DocumentNumberService $numbers,
    ) {}

    public function buatDariSuratJalan(SuratJalan $suratJalan, array $data, User $user): FakturPenjualan
    {
        return DB::transaction(function () use ($suratJalan, $data, $user) {
            $suratJalan = SuratJalan::with('details', 'pesanan', 'pelanggan')
                ->lockForUpdate()
                ->findOrFail($suratJalan->id);

            if (!$suratJalan->isPosted()) {
                throw new RuntimeException('Hanya surat jalan yang sudah diposting yang dapat difakturkan.');
            }

            $pesanan = $suratJalan->pesanan;
            $terminHari = (int) ($suratJalan->pelanggan?->termin_hari ?? 30);
            $tanggal = $data['tanggal'];

            $faktur = FakturPenjualan::create([
                'nomor' => $this->numbers->external('FJL'),
                'tanggal' => $tanggal,
                'jatuh_tempo' => $data['jatuh_tempo'] ?? now()->parse($tanggal)->addDays($terminHari)->toDateString(),
                'pelanggan_id' => $suratJalan->pelanggan_id,
                'sales_user_id' => $pesanan?->sales_user_id,
                'no_faktur_pajak' => $data['no_faktur_pajak'] ?? null,
                'is_ppn' => (bool) ($pesanan?->is_ppn ?? true),
                'tarif_ppn' => $pesanan?->tarif_ppn ?? 11,
                'status' => FakturPenjualan::DRAFT,
                'keterangan' => $data['keterangan'] ?? null,
                'dibuat_oleh' => $user->id,
            ]);

            $adaBaris = false;

            foreach ($suratJalan->details as $detail) {
                $sisa = $detail->sisaFaktur();

                if ($sisa <= 0.000001) {
                    continue;
                }

                $harga = (float) $detail->harga_satuan;

                $faktur->details()->create([
                    'surat_jalan_detail_id' => $detail->id,
                    'bahan_id' => $detail->bahan_id,
                    'jumlah' => $sisa,
                    'harga_satuan' => $harga,
                    'total_harga' => round($sisa * $harga, 2),
                ]);

                $adaBaris = true;
            }

            if (!$adaBaris) {
                throw new RuntimeException('Seluruh baris surat jalan ini sudah difakturkan.');
            }

            return $this->hitungTotal($faktur);
        });
    }

    public function hitungTotal(FakturPenjualan $faktur): FakturPenjualan
    {
        $faktur->loadMissing('details');

        $dpp = round((float) $faktur->details->sum('total_harga'), 2);
        $ppn = $faktur->is_ppn ? round($dpp * (float) $faktur->tarif_ppn / 100, 2) : 0.0;
        $total = round($dpp + $ppn, 2);

        $faktur->update([
            'total_dpp' => $dpp,
            'total_ppn' => $ppn,
            'grand_total' => $total,
            'sisa_tagihan' => $faktur->isDraft() ? $total : $faktur->sisa_tagihan,
        ]);

        return $faktur->fresh('details');
    }

    public function posting(FakturPenjualan $faktur, User $user): FakturPenjualan
    {
        return DB::transaction(function () use ($faktur, $user) {
            $faktur = FakturPenjualan::with('details')->lockForUpdate()->findOrFail($faktur->id);

            if (!$faktur->isDraft()) {
                throw new RuntimeException('Hanya faktur penjualan draft yang dapat diposting.');
            }

            if ($faktur->is_ppn && !$faktur->no_faktur_pajak) {
                throw new RuntimeException('Nomor seri faktur pajak wajib diisi untuk faktur ber-PPN.');
            }

            $jurnal = $this->akuntansi->postFakturPenjualan($faktur);

            foreach ($faktur->details as $baris) {
                SuratJalanDetail::whereKey($baris->surat_jalan_detail_id)
                    ->update(['jumlah_terfaktur' => DB::raw('jumlah_terfaktur + ' . (float) $baris->jumlah)]);
            }

            $faktur->update([
                'status' => FakturPenjualan::POSTED,
                'sisa_tagihan' => $faktur->grand_total,
                'journal_id' => $jurnal->id,
                'diposting_oleh' => $user->id,
                'diposting_pada' => now(),
            ]);

            return $faktur->fresh('details');
        });
    }

    public function terimaPembayaran(FakturPenjualan $faktur, array $data, User $user): PenerimaanPembayaran
    {
        return DB::transaction(function () use ($faktur, $data, $user) {
            $faktur = FakturPenjualan::lockForUpdate()->findOrFail($faktur->id);

            if (!$faktur->isTertagih()) {
                throw new RuntimeException('Faktur ini tidak dalam status yang dapat menerima pembayaran.');
            }

            $jumlah = round((float) $data['jumlah'], 2);

            if ($jumlah <= 0) {
                throw new RuntimeException('Nilai pembayaran harus lebih besar dari nol.');
            }

            $terpakai = round(min($jumlah, (float) $faktur->sisa_tagihan), 2);
            $kelebihan = round($jumlah - $terpakai, 2);

            $pembayaran = PenerimaanPembayaran::create([
                'nomor' => $this->numbers->external('RCP'),
                'tanggal' => $data['tanggal'],
                'faktur_penjualan_id' => $faktur->id,
                'pelanggan_id' => $faktur->pelanggan_id,
                'coa_kas_bank_id' => $data['coa_kas_bank_id'],
                'jumlah' => $terpakai,
                'jumlah_uang_muka' => $kelebihan,
                'referensi' => $data['referensi'] ?? null,
                'status' => PenerimaanPembayaran::POSTED,
                'dibuat_oleh' => $user->id,
            ]);

            $jurnal = $this->akuntansi->postPenerimaanPembayaran($pembayaran);
            $pembayaran->update(['journal_id' => $jurnal->id]);

            if ($kelebihan > 0) {
                UangMukaPelanggan::create([
                    'nomor' => $this->numbers->external('UMP'),
                    'tanggal' => $data['tanggal'],
                    'pelanggan_id' => $faktur->pelanggan_id,
                    'penerimaan_pembayaran_id' => $pembayaran->id,
                    'jumlah' => $kelebihan,
                    'sisa' => $kelebihan,
                    'status' => UangMukaPelanggan::AKTIF,
                    'keterangan' => "Kelebihan bayar faktur {$faktur->nomor}",
                    'dibuat_oleh' => $user->id,
                ]);
            }

            $this->sinkronSisaTagihan($faktur);

            return $pembayaran->fresh();
        });
    }

    public function gunakanUangMuka(FakturPenjualan $faktur, UangMukaPelanggan $uangMuka, float $jumlah, $tanggal, User $user): PenerimaanPembayaran
    {
        return DB::transaction(function () use ($faktur, $uangMuka, $jumlah, $tanggal, $user) {
            $faktur = FakturPenjualan::lockForUpdate()->findOrFail($faktur->id);
            $uangMuka = UangMukaPelanggan::lockForUpdate()->findOrFail($uangMuka->id);

            if (!$faktur->isTertagih()) {
                throw new RuntimeException('Faktur ini tidak dalam status yang dapat menerima pembayaran.');
            }

            if ((int) $uangMuka->pelanggan_id !== (int) $faktur->pelanggan_id) {
                throw new RuntimeException('Uang muka ini milik pelanggan lain.');
            }

            if (!$uangMuka->isAktif()) {
                throw new RuntimeException('Uang muka ini sudah habis terpakai.');
            }

            $jumlah = round($jumlah, 2);

            if ($jumlah <= 0) {
                throw new RuntimeException('Nilai pemakaian uang muka harus lebih besar dari nol.');
            }

            if ($jumlah > (float) $uangMuka->sisa + 0.005) {
                throw new RuntimeException('Nilai pemakaian melebihi sisa uang muka.');
            }

            if ($jumlah > (float) $faktur->sisa_tagihan + 0.005) {
                throw new RuntimeException('Nilai pemakaian melebihi sisa tagihan faktur.');
            }

            $pembayaran = PenerimaanPembayaran::create([
                'nomor' => $this->numbers->external('RCP'),
                'tanggal' => $tanggal,
                'faktur_penjualan_id' => $faktur->id,
                'pelanggan_id' => $faktur->pelanggan_id,
                'coa_kas_bank_id' => null,
                'uang_muka_id' => $uangMuka->id,
                'jumlah' => $jumlah,
                'jumlah_uang_muka' => 0,
                'referensi' => "Uang muka {$uangMuka->nomor}",
                'status' => PenerimaanPembayaran::POSTED,
                'dibuat_oleh' => $user->id,
            ]);

            $jurnal = $this->akuntansi->postPenerimaanPembayaran($pembayaran);
            $pembayaran->update(['journal_id' => $jurnal->id]);

            $sisa = round((float) $uangMuka->sisa - $jumlah, 2);
            $uangMuka->update([
                'sisa' => $sisa,
                'status' => $sisa > 0.005 ? UangMukaPelanggan::AKTIF : UangMukaPelanggan::TERPAKAI,
            ]);

            $this->sinkronSisaTagihan($faktur);

            return $pembayaran->fresh();
        });
    }

    public function sinkronSisaTagihan(FakturPenjualan $faktur): FakturPenjualan
    {
        $dibayar = round((float) $faktur->pembayaran()
            ->where('status', PenerimaanPembayaran::POSTED)
            ->sum('jumlah'), 2);

        $total = round((float) $faktur->grand_total, 2);

        $faktur->update([
            'sisa_tagihan' => max(0, round($total - $dibayar, 2)),
            'status' => FakturPenjualan::statusPembayaran($total, $dibayar),
        ]);

        return $faktur->fresh();
    }

    public function sisaBelumTerfaktur(SuratJalan $suratJalan)
    {
        return $suratJalan->details()
            ->get()
            ->filter(fn (SuratJalanDetail $detail) => $detail->sisaFaktur() > 0.000001);
    }
}
