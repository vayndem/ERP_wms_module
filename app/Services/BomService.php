<?php

namespace App\Services;

use App\Models\Bom;
use App\Models\DataPesanan;
use App\Models\LayerPersediaan;
use App\Models\PemakaianBarang;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class BomService
{
    private ?Collection $bomAktif = null;
    private ?Collection $pemakaianTerkumpul = null;
    private array $hargaPerGudang = [];

    public function buat(array $data, User $user): Bom
    {
        return DB::transaction(function () use ($data, $user) {
            $komponen = collect($data['details'])
                ->map(fn ($baris) => [
                    'bahan_id' => (int) $baris['bahan_id'],
                    'jumlah' => round((float) $baris['jumlah'], 6),
                    'satuan' => $baris['satuan'] ?? null,
                    'catatan' => $baris['catatan'] ?? null,
                ]);

            if ($komponen->pluck('bahan_id')->duplicates()->isNotEmpty()) {
                throw new RuntimeException('Satu bahan komponen hanya boleh muncul sekali dalam satu BOM.');
            }

            if ($komponen->pluck('bahan_id')->contains((int) $data['bahan_id'])) {
                throw new RuntimeException('Bahan hasil tidak boleh menjadi komponen dirinya sendiri.');
            }

            if ($komponen->contains(fn ($baris) => $baris['jumlah'] <= 0.000001)) {
                throw new RuntimeException('Jumlah komponen harus lebih besar dari nol.');
            }

            $jumlahHasil = round((float) ($data['jumlah_hasil'] ?? 1), 6);

            if ($jumlahHasil <= 0.000001) {
                throw new RuntimeException('Jumlah hasil BOM harus lebih besar dari nol.');
            }

            $this->pastikanBelumPunyaBomAktif((int) $data['bahan_id']);

            $bom = Bom::create([
                'kode' => $data['kode'],
                'nama' => $data['nama'],
                'bahan_id' => (int) $data['bahan_id'],
                'versi' => $data['versi'] ?? '1',
                'jumlah_hasil' => $jumlahHasil,
                'status' => Bom::AKTIF,
                'catatan' => $data['catatan'] ?? null,
                'dibuat_oleh' => $user->id,
            ]);

            $komponen->each(fn ($baris) => $bom->details()->create($baris));
            $this->bomAktif = null;

            return $bom->fresh('details.bahan');
        });
    }

    public function ubahStatus(Bom $bom, string $status): Bom
    {
        if (!in_array($status, [Bom::AKTIF, Bom::NONAKTIF], true)) {
            throw new RuntimeException('Status BOM tidak dikenal.');
        }

        if ($status === Bom::AKTIF) {
            $this->pastikanBelumPunyaBomAktif((int) $bom->bahan_id, (int) $bom->id);
        }

        $bom->update(['status' => $status]);
        $this->bomAktif = null;

        return $bom->fresh();
    }

    private function pastikanBelumPunyaBomAktif(int $bahanId, ?int $kecualiBomId = null): void
    {
        $bentrok = Bom::aktif()
            ->where('bahan_id', $bahanId)
            ->when($kecualiBomId, fn ($query) => $query->whereKeyNot($kecualiBomId))
            ->exists();

        if ($bentrok) {
            throw new RuntimeException('Bahan ini sudah punya BOM aktif. Nonaktifkan yang lama lebih dulu.');
        }
    }

    public function hapus(Bom $bom): void
    {
        if ($bom->isAktif()) {
            throw new RuntimeException('Nonaktifkan BOM lebih dulu sebelum menghapusnya.');
        }

        $bom->details()->delete();
        $bom->delete();
        $this->bomAktif = null;
    }

    public function bomUntuk(DataPesanan $pesanan): ?Bom
    {
        $this->bomAktif ??= Bom::aktif()
            ->with('details.bahan')
            ->orderBy('id')
            ->get()
            ->keyBy('bahan_id');

        return $this->bomAktif->get($pesanan->bahan_hasil_id);
    }

    public function varians(DataPesanan $pesanan): array
    {
        $bom = $this->bomUntuk($pesanan);
        $basis = $pesanan->sudahSelesai()
            ? (float) $pesanan->jumlah_selesai
            : (float) $pesanan->jumlah_rencana;

        $aktual = $this->pemakaianAktual($pesanan);

        if (!$bom) {
            return [
                'bom' => null,
                'basis' => $basis,
                'baris' => $aktual
                    ->map(fn ($baris, $bahanId) => $this->baris(
                        $bahanId,
                        $baris['nama'],
                        null,
                        $baris['jumlah'],
                        $baris['nilai'],
                        $baris['harga']
                    ))
                    ->values(),
                'total' => $this->total($aktual->map(fn ($baris) => $baris['nilai'])->sum(), 0.0),
            ];
        }

        $perUnit = max((float) $bom->jumlah_hasil, 0.000001);

        $standar = $bom->details->mapWithKeys(fn ($detail) => [
            (int) $detail->bahan_id => [
                'nama' => $detail->bahan?->nama ?? '-',
                'jumlah' => round((float) $detail->jumlah * $basis / $perUnit, 6),
            ],
        ]);

        $bahanIds = $standar->keys()->merge($aktual->keys())->unique();
        $hargaAcuan = $this->hargaAcuan($bahanIds, (int) $pesanan->gudang_id);

        $baris = $bahanIds->map(function ($bahanId) use ($standar, $aktual, $hargaAcuan) {
            $standarBaris = $standar[$bahanId] ?? null;
            $aktualBaris = $aktual[$bahanId] ?? null;
            $nama = $standarBaris['nama'] ?? $aktualBaris['nama'] ?? '-';
            $harga = $aktualBaris['harga'] ?? ($hargaAcuan[$bahanId] ?? null);

            return $this->baris(
                $bahanId,
                $nama,
                $standarBaris['jumlah'] ?? null,
                $aktualBaris['jumlah'] ?? 0.0,
                $aktualBaris['nilai'] ?? 0.0,
                $harga
            );
        })->sortBy('bahan')->values();

        $nilaiStandar = round($baris->sum(fn ($item) => $item['nilai_standar'] ?? 0), 2);
        $nilaiAktual = round($baris->sum('nilai_aktual'), 2);

        return [
            'bom' => $bom,
            'basis' => $basis,
            'baris' => $baris,
            'total' => $this->total($nilaiAktual, $nilaiStandar),
        ];
    }

    public function ringkasan(?int $limit = 50): Collection
    {
        $pesananList = DataPesanan::with('bahanHasil')
            ->whereNotIn('status', [DataPesanan::DIBATALKAN])
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $this->muatPemakaian($pesananList->pluck('id')->all());

        return $pesananList
            ->map(function (DataPesanan $pesanan) {
                $varians = $this->varians($pesanan);

                return [
                    'pesanan_id' => $pesanan->id,
                    'nomor' => $pesanan->nomor,
                    'status' => $pesanan->status,
                    'bahan_hasil' => $pesanan->bahanHasil?->nama ?? '-',
                    'punya_bom' => (bool) $varians['bom'],
                    'basis' => $varians['basis'],
                    'nilai_standar' => $varians['total']['nilai_standar'],
                    'nilai_aktual' => $varians['total']['nilai_aktual'],
                    'selisih_nilai' => $varians['total']['selisih_nilai'],
                ];
            });
    }

    private function baris(int $bahanId, string $nama, ?float $standar, float $aktual, float $nilaiAktual, ?float $harga): array
    {
        $selisih = $standar === null ? null : round($aktual - $standar, 6);
        $nilaiStandar = ($standar === null || $harga === null) ? null : round($standar * $harga, 2);

        return [
            'bahan_id' => $bahanId,
            'bahan' => $nama,
            'standar' => $standar,
            'aktual' => round($aktual, 6),
            'selisih' => $selisih,
            'harga_acuan' => $harga,
            'nilai_standar' => $nilaiStandar,
            'nilai_aktual' => round($nilaiAktual, 2),
            'selisih_nilai' => $nilaiStandar === null ? null : round($nilaiAktual - $nilaiStandar, 2),
            'status' => $this->statusBaris($standar, $aktual),
        ];
    }

    private function statusBaris(?float $standar, float $aktual): string
    {
        if ($standar === null) {
            return 'DI LUAR BOM';
        }

        if ($aktual <= 0.000001) {
            return 'BELUM DIPAKAI';
        }

        $selisih = $aktual - $standar;

        if (abs($selisih) <= 0.000001) {
            return 'SESUAI';
        }

        return $selisih > 0 ? 'BOROS' : 'HEMAT';
    }

    private function total(float $nilaiAktual, float $nilaiStandar): array
    {
        return [
            'nilai_standar' => round($nilaiStandar, 2),
            'nilai_aktual' => round($nilaiAktual, 2),
            'selisih_nilai' => round($nilaiAktual - $nilaiStandar, 2),
        ];
    }

    private function muatPemakaian(array $pesananIds): void
    {
        $this->pemakaianTerkumpul = $pesananIds === []
            ? collect()
            : PemakaianBarang::with('barang')
                ->whereIn('data_pesanan_id', $pesananIds)
                ->where('status', 'POSTED')
                ->get()
                ->groupBy('data_pesanan_id')
                ->map(fn (Collection $grup) => $this->rangkumPemakaian($grup));
    }

    private function pemakaianAktual(DataPesanan $pesanan): Collection
    {
        if ($this->pemakaianTerkumpul !== null) {
            return $this->pemakaianTerkumpul->get($pesanan->id, collect());
        }

        return $this->rangkumPemakaian(
            PemakaianBarang::with('barang')
                ->where('data_pesanan_id', $pesanan->id)
                ->where('status', 'POSTED')
                ->get()
        );
    }

    private function rangkumPemakaian(Collection $pemakaian): Collection
    {
        return $pemakaian
            ->groupBy('id_barang')
            ->map(function (Collection $grup) {
                $jumlah = round((float) $grup->sum('jumlah_stok'), 6);
                $nilai = round((float) $grup->sum('total_nilai'), 2);

                return [
                    'nama' => $grup->first()->barang?->nama ?? '-',
                    'jumlah' => $jumlah,
                    'nilai' => $nilai,
                    'harga' => $jumlah > 0.000001 ? round($nilai / $jumlah, 4) : null,
                ];
            });
    }

    private function hargaAcuan(Collection $bahanIds, int $gudangId): array
    {
        if ($bahanIds->isEmpty()) {
            return [];
        }

        $this->hargaPerGudang[$gudangId] ??= LayerPersediaan::where('gudang_id', $gudangId)
            ->orderByDesc('id')
            ->get(['bahan_id', 'unit_cost'])
            ->groupBy('bahan_id')
            ->map(fn ($grup) => round((float) $grup->first()->unit_cost, 4))
            ->all();

        return $this->hargaPerGudang[$gudangId];
    }
}
