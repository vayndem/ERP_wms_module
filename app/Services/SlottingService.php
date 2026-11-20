<?php

namespace App\Services;

use App\Models\LayerPersediaan;
use App\Models\LokasiGudang;
use App\Models\PengaturanBahanGudang;
use Illuminate\Support\Collection;

class SlottingService
{
    public function saran(int $gudangId, int $limit = 50): Collection
    {
        $kelasBahan = PengaturanBahanGudang::where('gudang_id', $gudangId)
            ->whereNotNull('kelas_abc')
            ->pluck('kelas_abc', 'bahan_id');

        if ($kelasBahan->isEmpty()) {
            return collect();
        }

        $lokasi = LokasiGudang::where('gudang_id', $gudangId)->where('active', true)->get();
        $kelasLokasi = $lokasi->whereNotNull('kelas_abc')->pluck('kelas_abc', 'id');

        if ($kelasLokasi->isEmpty()) {
            return collect();
        }

        $isiPerLokasi = LayerPersediaan::where('gudang_id', $gudangId)
            ->whereNotNull('warehouse_location_id')
            ->where('remaining_quantity', '>', 0)
            ->with('bahan')
            ->get()
            ->groupBy('warehouse_location_id');
        $terisi = $isiPerLokasi->map(fn (Collection $layers) => $layers->sum('remaining_quantity'));
        $volumeTerisi = $isiPerLokasi->map(fn (Collection $layers) => $layers->sum(
            fn (LayerPersediaan $layer) => (float) $layer->remaining_quantity * (float) ($layer->bahan?->volume_cm3 ?? 0)
        ));

        $saran = [];

        foreach ($isiPerLokasi as $lokasiId => $layers) {
            $kelasBin = $kelasLokasi->get($lokasiId);

            if (! $kelasBin) {
                continue;
            }

            foreach ($layers->groupBy('bahan_id') as $bahanId => $barisBahan) {
                $kelasMaterial = $kelasBahan->get($bahanId);

                if (! $kelasMaterial || $kelasMaterial === $kelasBin) {
                    continue;
                }

                $jumlah = (float) $barisBahan->sum('remaining_quantity');
                $tujuan = $this->lokasiTujuan($lokasi, $kelasMaterial, $jumlah, $barisBahan->first()->bahan, $terisi, $volumeTerisi);

                $saran[] = [
                    'bahan_id' => (int) $bahanId,
                    'bahan' => $barisBahan->first()->bahan?->nama ?? 'Bahan #'.$bahanId,
                    'kelas_bahan' => $kelasMaterial,
                    'lokasi_sekarang' => $lokasi->firstWhere('id', $lokasiId)?->code ?? '-',
                    'kelas_lokasi_sekarang' => $kelasBin,
                    'jumlah' => round($jumlah, 6),
                    'lokasi_saran' => $tujuan?->code,
                    'lokasi_saran_id' => $tujuan?->id,
                    'alasan' => $this->alasan($kelasMaterial, $kelasBin),
                ];
            }
        }

        return collect($saran)
            ->sortBy(fn ($row) => [$row['kelas_bahan'], $row['bahan']])
            ->take($limit)
            ->values();
    }

    private function lokasiTujuan(Collection $lokasi, string $kelas, float $jumlah, $bahan, Collection $terisi, Collection $volumeTerisi): ?LokasiGudang
    {
        return $lokasi
            ->where('kelas_abc', $kelas)
            ->sortBy(fn ($row) => $row->urutan_pick ?? PHP_INT_MAX)
            ->first(function (LokasiGudang $kandidat) use ($jumlah, $bahan, $terisi, $volumeTerisi) {
                if ($kandidat->capacity !== null && (float) ($terisi->get($kandidat->id) ?? 0) + $jumlah > (float) $kandidat->capacity + .000001) {
                    return false;
                }

                $kapasitasVolume = $kandidat->kapasitasVolume();
                $volumeSatuan = $bahan?->volume_cm3 !== null ? (float) $bahan->volume_cm3 : null;

                if ($kapasitasVolume !== null && $volumeSatuan !== null && $volumeSatuan > 0
                    && (float) ($volumeTerisi->get($kandidat->id) ?? 0) + $jumlah * $volumeSatuan > $kapasitasVolume + .01) {
                    return false;
                }

                return true;
            });
    }

    private function alasan(string $kelasBahan, string $kelasBin): string
    {
        return $kelasBahan < $kelasBin
            ? "Bahan kelas {$kelasBahan} (cepat bergerak) berada di bin kelas {$kelasBin} yang lebih jauh dari jalur ambil."
            : "Bahan kelas {$kelasBahan} (lambat bergerak) menempati bin kelas {$kelasBin} yang seharusnya untuk barang cepat bergerak.";
    }
}
