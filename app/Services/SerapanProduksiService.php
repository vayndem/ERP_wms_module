<?php

namespace App\Services;

use App\Models\DataPesanan;
use App\Models\JamKerjaProduksi;
use App\Models\PusatKerja;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SerapanProduksiService
{
    public const SUMBER = 'JAM_KERJA';

    public function __construct(
        private DataPesananService $dataPesanan,
        private WmsAccountingService $akuntansi,
        private DocumentNumberService $numbers,
    ) {}

    public function catat(DataPesanan $pesanan, PusatKerja $pusatKerja, array $data, User $user): JamKerjaProduksi
    {
        return DB::transaction(function () use ($pesanan, $pusatKerja, $data, $user) {
            $pesanan = DataPesanan::lockForUpdate()->findOrFail($pesanan->id);
            $pusatKerja = PusatKerja::lockForUpdate()->findOrFail($pusatKerja->id);

            if (!$pesanan->menerimaBiaya()) {
                throw new RuntimeException(
                    "Perintah kerja {$pesanan->nomor} sudah tidak menerima biaya baru, jadi jam kerja tidak dapat diserap ke sini."
                );
            }

            if ($pusatKerja->status !== PusatKerja::AKTIF) {
                throw new RuntimeException('Pusat kerja ini sudah tidak aktif.');
            }

            $jam = round((float) $data['jam'], 2);

            if ($jam <= 0) {
                throw new RuntimeException('Jam kerja harus lebih besar dari nol.');
            }

            $tarifTenagaKerja = round((float) $pusatKerja->tarif_tenaga_kerja_per_jam, 2);
            $tarifOverhead = round((float) $pusatKerja->tarif_overhead_per_jam, 2);

            if ($tarifTenagaKerja <= 0 && $tarifOverhead <= 0) {
                throw new RuntimeException(
                    "Pusat kerja {$pusatKerja->nama} belum punya tarif per jam, jadi tidak ada yang bisa diserap. Isi tarifnya lebih dulu."
                );
            }

            $biayaTenagaKerja = round($jam * $tarifTenagaKerja, 2);
            $biayaOverhead = round($jam * $tarifOverhead, 2);

            $jamKerja = JamKerjaProduksi::create([
                'nomor' => $this->numbers->internal('JKP', 'PRD'),
                'tanggal' => $data['tanggal'],
                'data_pesanan_id' => $pesanan->id,
                'pusat_kerja_id' => $pusatKerja->id,
                'jam' => $jam,
                'tarif_tenaga_kerja_per_jam' => $tarifTenagaKerja,
                'tarif_overhead_per_jam' => $tarifOverhead,
                'biaya_tenaga_kerja' => $biayaTenagaKerja,
                'biaya_overhead' => $biayaOverhead,
                'keterangan' => $data['keterangan'] ?? null,
                'dibuat_oleh' => $user->id,
            ]);

            $jurnal = $this->akuntansi->postSerapanProduksi($jamKerja);
            $jamKerja->update(['journal_id' => $jurnal->id]);

            $this->dataPesanan->catatBiaya(
                $pesanan,
                self::SUMBER,
                (int) $jamKerja->id,
                $jamKerja->totalBiaya(),
                $jamKerja->tanggal,
                sprintf(
                    '%s jam di %s (tenaga kerja %s + overhead %s)',
                    number_format($jam, 2, ',', '.'),
                    $pusatKerja->nama,
                    number_format($biayaTenagaKerja, 2, ',', '.'),
                    number_format($biayaOverhead, 2, ',', '.')
                )
            );

            return $jamKerja->fresh(['pusatKerja', 'pesanan']);
        });
    }

    public function batalkan(JamKerjaProduksi $jamKerja): void
    {
        DB::transaction(function () use ($jamKerja) {
            $jamKerja = JamKerjaProduksi::with('pesanan')->lockForUpdate()->findOrFail($jamKerja->id);
            $pesanan = $jamKerja->pesanan;

            if (!$pesanan || !$pesanan->menerimaBiaya()) {
                throw new RuntimeException(
                    'Perintah kerja ini sudah disegel, jadi jam kerjanya tidak dapat ditarik kembali. Catat koreksinya pada perintah kerja baru.'
                );
            }

            $this->dataPesanan->hapusBiaya($pesanan, self::SUMBER, (int) $jamKerja->id);
            $this->akuntansi->deleteAutomaticJournal('SERAPAN_PRODUKSI', (int) $jamKerja->id);

            $jamKerja->delete();
        });
    }

    public function ringkasan(DataPesanan $pesanan): array
    {
        $baris = JamKerjaProduksi::with('pusatKerja')
            ->where('data_pesanan_id', $pesanan->id)
            ->orderBy('tanggal')
            ->get();

        return [
            'baris' => $baris,
            'total_jam' => round((float) $baris->sum('jam'), 2),
            'total_tenaga_kerja' => round((float) $baris->sum('biaya_tenaga_kerja'), 2),
            'total_overhead' => round((float) $baris->sum('biaya_overhead'), 2),
            'total' => round((float) $baris->sum(fn (JamKerjaProduksi $row) => $row->totalBiaya()), 2),
        ];
    }
}
