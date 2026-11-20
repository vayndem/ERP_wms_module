<?php

namespace App\Services;

use App\Models\Bahan;
use App\Models\Kit;
use App\Models\LayerPersediaan;
use App\Models\PerakitanKit;
use App\Models\StokGudang;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class KittingService
{
    public function __construct(
        private StokGudangService $stok,
        private DocumentNumberService $numbers,
        private WmsAccountingService $accounting,
    ) {}

    public function kebutuhan(Kit $kit, float $jumlahKit): array
    {
        $kit->loadMissing('komponen.bahan');

        if ($kit->komponen->isEmpty()) {
            throw new RuntimeException('Kit ini belum memiliki komponen.');
        }

        $faktor = $jumlahKit / (float) $kit->jumlah_hasil;

        return $kit->komponen->map(fn ($komponen) => [
            'bahan_id' => (int) $komponen->bahan_id,
            'nama' => $komponen->bahan->nama ?? 'Bahan #' . $komponen->bahan_id,
            'satuan' => $komponen->bahan->satuan ?? '',
            'jumlah' => round((float) $komponen->jumlah * $faktor, 6),
        ])->all();
    }

    public function ketersediaan(Kit $kit, int $gudangId, float $jumlahKit): array
    {
        $kebutuhan = collect($this->kebutuhan($kit, $jumlahKit));
        $saldoPerBahan = StokGudang::where('gudang_id', $gudangId)
            ->whereIn('bahan_id', $kebutuhan->pluck('bahan_id'))
            ->get()
            ->keyBy('bahan_id');

        return $kebutuhan->map(function ($baris) use ($saldoPerBahan) {
            $saldo = $saldoPerBahan->get($baris['bahan_id']);
            $bebas = $saldo ? (float) $saldo->stok_tersedia - (float) $saldo->stok_direservasi : 0.0;

            return $baris + [
                'tersedia' => round($bebas, 6),
                'cukup' => $bebas + 0.000001 >= $baris['jumlah'],
            ];
        })->all();
    }

    public function rakit(Kit $kit, int $gudangId, float $jumlahKit, string $tanggal, ?string $catatan = null): PerakitanKit
    {
        return $this->jalankan($kit, $gudangId, $jumlahKit, $tanggal, $catatan, PerakitanKit::RAKIT);
    }

    public function urai(Kit $kit, int $gudangId, float $jumlahKit, string $tanggal, ?string $catatan = null): PerakitanKit
    {
        return $this->jalankan($kit, $gudangId, $jumlahKit, $tanggal, $catatan, PerakitanKit::URAI);
    }

    private function jalankan(Kit $kit, int $gudangId, float $jumlahKit, string $tanggal, ?string $catatan, string $jenis): PerakitanKit
    {
        if ($jumlahKit <= 0) {
            throw new RuntimeException('Jumlah kit harus lebih besar dari nol.');
        }

        if (!$kit->aktif) {
            throw new RuntimeException('Kit ini sudah tidak aktif.');
        }

        return DB::transaction(function () use ($kit, $gudangId, $jumlahKit, $tanggal, $catatan, $jenis) {
            $kebutuhan = $this->kebutuhan($kit, $jumlahKit);

            $perakitan = PerakitanKit::create([
                'nomor' => $this->numbers->internal($jenis === PerakitanKit::RAKIT ? 'KIT' : 'UKT', 'WH'),
                'kit_id' => $kit->id,
                'gudang_id' => $gudangId,
                'jenis' => $jenis,
                'tanggal' => $tanggal,
                'jumlah_kit' => $jumlahKit,
                'status' => PerakitanKit::DRAFT,
                'catatan' => $catatan,
                'dibuat_oleh' => Auth::id(),
            ]);

            $nilaiTotal = $jenis === PerakitanKit::RAKIT
                ? $this->konsumsiKomponen($perakitan, $gudangId, $kebutuhan, $tanggal)
                : $this->konsumsiKit($perakitan, $kit, $gudangId, $jumlahKit, $tanggal);

            if ($jenis === PerakitanKit::RAKIT) {
                $this->hasilkanKit($perakitan, $kit, $gudangId, $jumlahKit, $nilaiTotal, $tanggal);
            } else {
                $this->hasilkanKomponen($perakitan, $gudangId, $kebutuhan, $nilaiTotal, $tanggal);
            }

            $perakitan->update([
                'nilai_total' => round($nilaiTotal, 2),
                'status' => PerakitanKit::POSTED,
                'diposting_oleh' => Auth::id(),
                'diposting_pada' => now(),
            ]);

            $journal = $this->accounting->postPerakitanKit($perakitan->fresh(['kit.bahanHasil', 'details.bahan']));
            $perakitan->update(['journal_id' => $journal?->id]);

            return $perakitan->fresh(['details.bahan', 'kit']);
        });
    }

    private function konsumsiKomponen(PerakitanKit $perakitan, int $gudangId, array $kebutuhan, string $tanggal): float
    {
        $total = 0.0;

        foreach ($kebutuhan as $baris) {
            $alokasi = $this->stok->ambilLayer($gudangId, $baris['bahan_id'], $baris['jumlah'], $tanggal);
            $nilai = 0.0;

            foreach ($alokasi as $bagian) {
                $nilai += $bagian['jumlah'] * $bagian['harga'];
            }

            $this->stok->keluar($gudangId, $baris['bahan_id'], $baris['jumlah'],
                $baris['jumlah'] > 0 ? $nilai / $baris['jumlah'] : 0,
                'PERAKITAN_KIT_KELUAR', 'PERAKITAN_KIT', $perakitan->id, "Komponen {$perakitan->nomor}");

            $perakitan->details()->create([
                'bahan_id' => $baris['bahan_id'],
                'jumlah' => $baris['jumlah'],
                'nilai' => round($nilai, 2),
            ]);

            $total += $nilai;
        }

        return $total;
    }

    private function konsumsiKit(PerakitanKit $perakitan, Kit $kit, int $gudangId, float $jumlahKit, string $tanggal): float
    {
        $alokasi = $this->stok->ambilLayer($gudangId, (int) $kit->bahan_hasil_id, $jumlahKit, $tanggal);
        $nilai = 0.0;

        foreach ($alokasi as $bagian) {
            $nilai += $bagian['jumlah'] * $bagian['harga'];
        }

        $this->stok->keluar($gudangId, (int) $kit->bahan_hasil_id, $jumlahKit,
            $jumlahKit > 0 ? $nilai / $jumlahKit : 0,
            'PERAKITAN_KIT_KELUAR', 'PERAKITAN_KIT', $perakitan->id, "Urai kit {$perakitan->nomor}");

        return $nilai;
    }

    private function hasilkanKit(PerakitanKit $perakitan, Kit $kit, int $gudangId, float $jumlahKit, float $nilaiTotal, string $tanggal): void
    {
        $hargaSatuan = $jumlahKit > 0 ? round($nilaiTotal / $jumlahKit, 4) : 0;

        LayerPersediaan::create([
            'bahan_id' => $kit->bahan_hasil_id,
            'gudang_id' => $gudangId,
            'source_type' => 'PERAKITAN_KIT',
            'source_id' => $perakitan->id,
            'transaction_date' => $tanggal,
            'initial_quantity' => $jumlahKit,
            'remaining_quantity' => $jumlahKit,
            'unit_cost' => $hargaSatuan,
        ]);

        $this->stok->masuk($gudangId, (int) $kit->bahan_hasil_id, $jumlahKit, $hargaSatuan,
            'PERAKITAN_KIT_MASUK', 'PERAKITAN_KIT', $perakitan->id, "Hasil kit {$perakitan->nomor}");
    }

    private function hasilkanKomponen(PerakitanKit $perakitan, int $gudangId, array $kebutuhan, float $nilaiTotal, string $tanggal): void
    {
        $totalUnit = array_sum(array_column($kebutuhan, 'jumlah'));

        foreach ($kebutuhan as $baris) {
            $porsi = $totalUnit > 0 ? $nilaiTotal * ($baris['jumlah'] / $totalUnit) : 0;
            $hargaSatuan = $baris['jumlah'] > 0 ? round($porsi / $baris['jumlah'], 4) : 0;

            $detail = $perakitan->details()->create([
                'bahan_id' => $baris['bahan_id'],
                'jumlah' => $baris['jumlah'],
                'nilai' => round($porsi, 2),
            ]);

            LayerPersediaan::create([
                'bahan_id' => $baris['bahan_id'],
                'gudang_id' => $gudangId,
                'source_type' => 'PERAKITAN_KIT_DETAIL',
                'source_id' => $detail->id,
                'transaction_date' => $tanggal,
                'initial_quantity' => $baris['jumlah'],
                'remaining_quantity' => $baris['jumlah'],
                'unit_cost' => $hargaSatuan,
            ]);

            $this->stok->masuk($gudangId, $baris['bahan_id'], $baris['jumlah'], $hargaSatuan,
                'PERAKITAN_KIT_MASUK', 'PERAKITAN_KIT', $perakitan->id, "Hasil urai {$perakitan->nomor}");
        }
    }
}
