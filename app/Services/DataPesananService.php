<?php

namespace App\Services;

use App\Models\DataPesanan;
use App\Models\DataPesananBiaya;
use App\Models\PemakaianBarang;
use App\Models\PesananPenjualanDetail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DataPesananService
{
    public const SUMBER_PEMBATALAN = 'PEMBATALAN';

    public function __construct(private DocumentNumberService $numbers) {}

    public function buat(PesananPenjualanDetail $detailPesanan, array $data, User $user): DataPesanan
    {
        return DB::transaction(function () use ($detailPesanan, $data, $user) {
            $detailPesanan->loadMissing('pesanan');
            $pesanan = $detailPesanan->pesanan;

            if (!$pesanan) {
                throw new RuntimeException('Baris pesanan penjualan tidak memiliki header.');
            }

            $jumlah = round((float) $data['jumlah_rencana'], 6);

            if ($jumlah <= 0) {
                throw new RuntimeException('Jumlah rencana produksi harus lebih besar dari nol.');
            }

            return DataPesanan::create([
                'nomor' => $this->numbers->external('WOP'),
                'tanggal' => $data['tanggal'],
                'pesanan_penjualan_id' => $pesanan->id,
                'pesanan_penjualan_detail_id' => $detailPesanan->id,
                'bahan_hasil_id' => $detailPesanan->bahan_id,
                'gudang_id' => $data['gudang_id'] ?? $pesanan->gudang_id,
                'jumlah_rencana' => $jumlah,
                'status' => DataPesanan::DRAFT,
                'keterangan' => $data['keterangan'] ?? null,
                'dibuat_oleh' => $user->id,
            ]);
        });
    }

    public function rilis(DataPesanan $pesanan): DataPesanan
    {
        if ($pesanan->status !== DataPesanan::DRAFT) {
            throw new RuntimeException('Hanya perintah kerja draft yang dapat dirilis.');
        }

        $pesanan->update(['status' => DataPesanan::DIRILIS]);

        return $pesanan->fresh();
    }

    public function catatBiaya(DataPesanan $pesanan, string $sumber, int $referensiId, float $nilai, $tanggal, ?string $keterangan = null): ?DataPesananBiaya
    {
        $nilai = round($nilai, 2);

        if ($nilai <= 0) {
            return null;
        }

        if (!$pesanan->menerimaBiaya()) {
            throw new RuntimeException(
                "Perintah kerja {$pesanan->nomor} sudah dilaporkan selesai dan tidak dapat menerima biaya baru. Buat perintah kerja baru untuk biaya susulan."
            );
        }

        return DataPesananBiaya::updateOrCreate(
            ['data_pesanan_id' => $pesanan->id, 'sumber' => $sumber, 'referensi_id' => $referensiId],
            ['tanggal' => $tanggal, 'nilai' => $nilai, 'keterangan' => $keterangan]
        );
    }

    public function hapusBiaya(DataPesanan $pesanan, string $sumber, int $referensiId): void
    {
        if ($pesanan->sudahSelesai()) {
            throw new RuntimeException(
                "Biaya perintah kerja {$pesanan->nomor} tidak dapat ditarik setelah dilaporkan selesai."
            );
        }

        DataPesananBiaya::where('data_pesanan_id', $pesanan->id)
            ->where('sumber', $sumber)
            ->where('referensi_id', $referensiId)
            ->delete();
    }

    public function selesaikan(DataPesanan $pesanan, float $jumlahSelesai, User $user): DataPesanan
    {
        return DB::transaction(function () use ($pesanan, $jumlahSelesai, $user) {
            $pesanan = DataPesanan::lockForUpdate()->findOrFail($pesanan->id);

            if ($pesanan->status !== DataPesanan::DIRILIS) {
                throw new RuntimeException('Hanya perintah kerja yang sudah dirilis yang dapat dilaporkan selesai.');
            }

            $jumlahSelesai = round($jumlahSelesai, 6);

            if ($jumlahSelesai <= 0) {
                throw new RuntimeException('Jumlah selesai harus lebih besar dari nol.');
            }

            $biaya = $pesanan->totalBiaya();

            if ($biaya <= 0) {
                throw new RuntimeException('Perintah kerja belum menyerap biaya apa pun, jadi belum dapat dilaporkan selesai.');
            }

            $pesanan->update([
                'jumlah_selesai' => $jumlahSelesai,
                'biaya_per_unit' => round($biaya / $jumlahSelesai, 4),
                'status' => DataPesanan::SELESAI,
                'diselesaikan_oleh' => $user->id,
                'diselesaikan_pada' => now(),
            ]);

            return $pesanan->fresh();
        });
    }

    public function lepaskanUntukPengiriman(DataPesanan $pesanan, float $jumlah): float
    {
        $pesanan = DataPesanan::lockForUpdate()->findOrFail($pesanan->id);

        if (!$pesanan->sudahSelesai()) {
            throw new RuntimeException(
                "Perintah kerja {$pesanan->nomor} belum dilaporkan selesai, jadi belum ada harga pokok yang bisa dilepas."
            );
        }

        $jumlah = round($jumlah, 6);

        if ($jumlah > $pesanan->jumlahBelumTerkirim() + 0.000001) {
            throw new RuntimeException(
                "Jumlah kirim melebihi hasil produksi perintah kerja {$pesanan->nomor} yang belum terkirim."
            );
        }

        $menghabiskan = round($pesanan->jumlahBelumTerkirim() - $jumlah, 6) <= 0.000001;

        $nilai = $menghabiskan
            ? max(0.0, round($pesanan->totalBiaya() - $pesanan->totalDilepas(), 2))
            : round($jumlah * (float) $pesanan->biaya_per_unit, 2);

        $pesanan->update([
            'jumlah_terkirim' => round((float) $pesanan->jumlah_terkirim + $jumlah, 6),
        ]);

        if ($pesanan->fresh()->jumlahBelumTerkirim() <= 0.000001) {
            $pesanan->update(['status' => DataPesanan::DITUTUP]);
        }

        return $nilai;
    }

    public function kembalikanPelepasan(DataPesanan $pesanan, float $jumlah): void
    {
        $pesanan = DataPesanan::lockForUpdate()->findOrFail($pesanan->id);

        $pesanan->update([
            'jumlah_terkirim' => max(0, round((float) $pesanan->jumlah_terkirim - round($jumlah, 6), 6)),
            'status' => $pesanan->status === DataPesanan::DITUTUP ? DataPesanan::SELESAI : $pesanan->status,
        ]);
    }

    public function batalkan(DataPesanan $pesanan, ?WmsAccountingService $akuntansi = null): DataPesanan
    {
        return DB::transaction(function () use ($pesanan, $akuntansi) {
            $pesanan = DataPesanan::lockForUpdate()->findOrFail($pesanan->id);

            if ($pesanan->status === DataPesanan::DIBATALKAN) {
                throw new RuntimeException('Perintah kerja ini sudah dibatalkan.');
            }

            if ($pesanan->sudahSelesai()) {
                throw new RuntimeException('Perintah kerja yang sudah selesai tidak dapat dibatalkan.');
            }

            $wip = $pesanan->totalBiaya();

            if ($wip > 0.005) {
                ($akuntansi ?? app(WmsAccountingService::class))->postPembatalanPerintahKerja($pesanan, $wip);

                DataPesananBiaya::create([
                    'data_pesanan_id' => $pesanan->id,
                    'sumber' => self::SUMBER_PEMBATALAN,
                    'referensi_id' => $pesanan->id,
                    'tanggal' => now()->toDateString(),
                    'nilai' => -$wip,
                    'keterangan' => 'Hapus buku barang dalam proses karena perintah kerja dibatalkan.',
                ]);
            }

            $pesanan->update(['status' => DataPesanan::DIBATALKAN]);

            return $pesanan->fresh();
        });
    }

    public function dariPemakaian(PemakaianBarang $npk): ?DataPesanan
    {
        return $npk->data_pesanan_id ? DataPesanan::find($npk->data_pesanan_id) : null;
    }

    public function saldoWipSeluruh(): float
    {
        return round(
            DataPesanan::berjalan()->get()->sum(fn (DataPesanan $pesanan) => $pesanan->saldoWip()),
            2
        );
    }
}
