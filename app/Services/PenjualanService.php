<?php

namespace App\Services;

use App\Models\DataPesanan;
use App\Models\LayerPersediaan;
use App\Models\Pelanggan;
use App\Models\PesananPenjualan;
use App\Models\PesananPenjualanDetail;
use App\Models\SuratJalan;
use App\Models\SuratJalanAlokasi;
use App\Models\SuratJalanDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PenjualanService
{
    public function __construct(
        private StokGudangService $stok,
        private WmsAccountingService $akuntansi,
        private DocumentNumberService $numbers,
        private DataPesananService $dataPesanan,
        private CrossDockService $crossDock,
        private PlafonKreditService $plafon,
    ) {}

    private function nilaiPesananBaru(array $data): float
    {
        $dpp = collect($data['details'] ?? [])->sum(
            fn ($baris) => round((float) $baris['jumlah'], 6) * round((float) $baris['harga_satuan'], 2)
        );

        $tarif = (bool) ($data['is_ppn'] ?? true) ? (float) ($data['tarif_ppn'] ?? 11) : 0.0;

        return round($dpp * (1 + $tarif / 100), 2);
    }

    public function buatPesanan(array $data, User $user): PesananPenjualan
    {
        return DB::transaction(function () use ($data, $user) {
            $pelanggan = Pelanggan::lockForUpdate()->findOrFail($data['pelanggan_id']);
            $nilaiBaru = $this->nilaiPesananBaru($data);
            $plafon = $this->plafon->periksa($pelanggan, $nilaiBaru, $user, $data['alasan_plafon'] ?? null);

            $pesanan = PesananPenjualan::create([
                'nomor' => $this->numbers->external('SOP'),
                'tanggal' => $data['tanggal'],
                'pelanggan_id' => $data['pelanggan_id'],
                'sales_user_id' => $data['sales_user_id'] ?? $user->id,
                'gudang_id' => $data['gudang_id'],
                'nomor_po_pelanggan' => $data['nomor_po_pelanggan'] ?? null,
                'is_ppn' => (bool) ($data['is_ppn'] ?? true),
                'tarif_ppn' => $data['tarif_ppn'] ?? 11,
                'status' => PesananPenjualan::OPEN,
                'keterangan' => $data['keterangan'] ?? null,
                'dibuat_oleh' => $user->id,
                'plafon_dilampaui' => $plafon['dilampaui'],
                'alasan_plafon' => $plafon['dilampaui'] ? trim((string) ($data['alasan_plafon'] ?? '')) : null,
                'plafon_disetujui_oleh' => $plafon['dilampaui'] ? $user->id : null,
                'eksposur_saat_dibuat' => $plafon['total'],
            ]);

            foreach ($data['details'] as $baris) {
                $jumlah = round((float) $baris['jumlah'], 6);
                $harga = round((float) $baris['harga_satuan'], 2);

                $pesanan->details()->create([
                    'bahan_id' => $baris['bahan_id'],
                    'jumlah' => $jumlah,
                    'harga_satuan' => $harga,
                    'total_harga' => round($jumlah * $harga, 2),
                    'satuan' => $baris['satuan'] ?? null,
                ]);
            }

            return $this->hitungTotal($pesanan);
        });
    }

    public function hitungTotal(PesananPenjualan $pesanan): PesananPenjualan
    {
        $pesanan->loadMissing('details');

        $dpp = round((float) $pesanan->details->sum('total_harga'), 2);
        $ppn = $pesanan->is_ppn ? round($dpp * (float) $pesanan->tarif_ppn / 100, 2) : 0.0;

        $pesanan->update([
            'total_dpp' => $dpp,
            'total_ppn' => $ppn,
            'grand_total' => round($dpp + $ppn, 2),
        ]);

        return $pesanan->fresh('details');
    }

    public function buatSuratJalan(PesananPenjualan $pesanan, array $data, User $user): SuratJalan
    {
        return DB::transaction(function () use ($pesanan, $data, $user) {
            $pesanan = PesananPenjualan::with('details')->lockForUpdate()->findOrFail($pesanan->id);

            if (!$pesanan->isOpen()) {
                throw new RuntimeException('Hanya pesanan penjualan berstatus OPEN yang dapat dikirim.');
            }

            $suratJalan = SuratJalan::create([
                'nomor' => $this->numbers->external('SJL'),
                'tanggal' => $data['tanggal'],
                'pesanan_penjualan_id' => $pesanan->id,
                'pelanggan_id' => $pesanan->pelanggan_id,
                'gudang_id' => $pesanan->gudang_id,
                'nomor_kendaraan' => $data['nomor_kendaraan'] ?? null,
                'pengirim' => $data['pengirim'] ?? null,
                'status' => SuratJalan::DRAFT,
                'keterangan' => $data['keterangan'] ?? null,
                'dibuat_oleh' => $user->id,
            ]);

            $adaBaris = false;

            foreach ($data['details'] as $baris) {
                $jumlah = round((float) ($baris['jumlah'] ?? 0), 6);

                if ($jumlah <= 0) {
                    continue;
                }

                $detailPesanan = $pesanan->details->firstWhere('id', (int) $baris['pesanan_penjualan_detail_id']);

                if (!$detailPesanan) {
                    throw new RuntimeException('Baris pesanan penjualan tidak ditemukan.');
                }

                if ($jumlah > $detailPesanan->sisaKirim() + 0.000001) {
                    throw new RuntimeException("Jumlah kirim {$detailPesanan->bahan?->nama} melebihi sisa pesanan.");
                }

                $pesananProduksi = DataPesanan::where('pesanan_penjualan_detail_id', $detailPesanan->id)
                    ->where('status', DataPesanan::SELESAI)
                    ->get()
                    ->first(fn (DataPesanan $wo) => $wo->jumlahBelumTerkirim() + 0.000001 >= $jumlah);

                $suratJalan->details()->create([
                    'pesanan_penjualan_detail_id' => $detailPesanan->id,
                    'bahan_id' => $detailPesanan->bahan_id,
                    'data_pesanan_id' => $pesananProduksi?->id,
                    'jumlah' => $jumlah,
                    'harga_satuan' => $detailPesanan->harga_satuan,
                ]);

                $adaBaris = true;
            }

            if (!$adaBaris) {
                throw new RuntimeException('Surat jalan harus memiliki minimal satu baris dengan jumlah lebih dari nol.');
            }

            return $suratJalan->fresh('details');
        });
    }

    public function postingSuratJalan(SuratJalan $suratJalan, User $user): SuratJalan
    {
        return DB::transaction(function () use ($suratJalan, $user) {
            $suratJalan = SuratJalan::with('details.bahan', 'pesanan')->lockForUpdate()->findOrFail($suratJalan->id);

            if (!$suratJalan->isDraft()) {
                throw new RuntimeException('Hanya surat jalan draft yang dapat diposting.');
            }

            $this->crossDock->lepaskanUntukPengiriman($suratJalan);

            $totalHpp = 0.0;

            foreach ($suratJalan->details as $detail) {
                $jumlah = (float) $detail->jumlah;

                if ($detail->data_pesanan_id) {
                    $hpp = $this->dataPesanan->lepaskanUntukPengiriman(
                        DataPesanan::findOrFail($detail->data_pesanan_id),
                        $jumlah
                    );

                    $detail->update(['hpp' => $hpp]);
                    $totalHpp += $hpp;

                    PesananPenjualanDetail::whereKey($detail->pesanan_penjualan_detail_id)
                        ->update(['jumlah_terkirim' => DB::raw('jumlah_terkirim + ' . $jumlah)]);

                    continue;
                }

                $alokasi = $this->stok->ambilLayer(
                    (int) $suratJalan->gudang_id,
                    (int) $detail->bahan_id,
                    $jumlah,
                    $suratJalan->tanggal
                );

                $hpp = 0.0;

                foreach ($alokasi as $bagian) {
                    $nilai = round((float) $bagian['jumlah'] * (float) $bagian['harga'], 2);
                    $hpp += $nilai;

                    SuratJalanAlokasi::create([
                        'surat_jalan_detail_id' => $detail->id,
                        'inventory_layer_id' => $bagian['layer']->id,
                        'jumlah' => $bagian['jumlah'],
                        'harga_satuan' => $bagian['harga'],
                        'total_hpp' => $nilai,
                    ]);
                }

                $this->stok->keluar(
                    (int) $suratJalan->gudang_id,
                    (int) $detail->bahan_id,
                    $jumlah,
                    $jumlah > 0 ? $hpp / $jumlah : 0,
                    'PENJUALAN_KELUAR',
                    'SURAT_JALAN',
                    $suratJalan->id,
                    $suratJalan->nomor
                );

                $detail->update(['hpp' => round($hpp, 2)]);
                $totalHpp += $hpp;

                PesananPenjualanDetail::whereKey($detail->pesanan_penjualan_detail_id)
                    ->update(['jumlah_terkirim' => DB::raw('jumlah_terkirim + ' . $jumlah)]);
            }

            $suratJalan->update([
                'total_hpp' => round($totalHpp, 2),
                'status' => SuratJalan::POSTED,
                'diposting_oleh' => $user->id,
                'diposting_pada' => now(),
            ]);

            $jurnal = $this->akuntansi->postSuratJalan($suratJalan->fresh('details.bahan.tipeBarang'));
            $suratJalan->update(['journal_id' => $jurnal->id]);

            $this->sinkronStatusPesanan($suratJalan->pesanan);

            return $suratJalan->fresh('details');
        });
    }

    public function sinkronStatusPesanan(PesananPenjualan $pesanan): void
    {
        $pesanan->loadMissing('details');

        $tuntas = $pesanan->details->every(
            fn (PesananPenjualanDetail $detail) => $detail->sisaKirim() <= 0.000001
        );

        if ($tuntas && $pesanan->isOpen()) {
            $pesanan->update(['status' => PesananPenjualan::CLOSED]);
        }

        if (!$tuntas && $pesanan->status === PesananPenjualan::CLOSED) {
            $pesanan->update(['status' => PesananPenjualan::OPEN]);
        }
    }

    public function sisaBelumTerfaktur(SuratJalan $suratJalan)
    {
        return $suratJalan->details()
            ->get()
            ->filter(fn (SuratJalanDetail $detail) => $detail->sisaFaktur() > 0.000001);
    }
}
