<?php

namespace App\Services;

use App\Models\Bom;
use App\Models\DataPesanan;
use App\Models\OperasiBom;
use App\Models\PusatKerja;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RoutingProduksiService
{
    public function __construct(private BomService $bom) {}

    public function simpanPusatKerja(array $data, User $user): PusatKerja
    {
        if (isset($data['kapasitas_menit_per_hari']) && (float) $data['kapasitas_menit_per_hari'] > 1440) {
            throw new RuntimeException('Kapasitas satu pusat kerja tidak bisa melebihi 1.440 menit per hari.');
        }

        return PusatKerja::create([
            'kode' => $data['kode'],
            'nama' => $data['nama'],
            'gudang_id' => $data['gudang_id'] ?? null,
            'kapasitas_menit_per_hari' => $data['kapasitas_menit_per_hari'] ?? null,
            'status' => PusatKerja::AKTIF,
            'keterangan' => $data['keterangan'] ?? null,
            'dibuat_oleh' => $user->id,
        ]);
    }

    public function ubahStatusPusatKerja(PusatKerja $pusatKerja, string $status): PusatKerja
    {
        if (! in_array($status, [PusatKerja::AKTIF, PusatKerja::NONAKTIF], true)) {
            throw new RuntimeException('Status pusat kerja tidak dikenal.');
        }

        if ($status === PusatKerja::NONAKTIF && $pusatKerja->operasi()->exists()) {
            throw new RuntimeException('Pusat kerja masih dipakai routing BOM dan tidak dapat dinonaktifkan.');
        }

        $pusatKerja->update(['status' => $status]);

        return $pusatKerja->fresh();
    }

    public function simpanOperasi(Bom $bom, array $operasi): Bom
    {
        return DB::transaction(function () use ($bom, $operasi) {
            if (empty($operasi)) {
                throw new RuntimeException('Routing wajib berisi minimal satu operasi.');
            }

            $baris = collect($operasi)->map(fn ($item, $index) => [
                'pusat_kerja_id' => (int) $item['pusat_kerja_id'],
                'urutan' => (int) ($item['urutan'] ?? $index + 1),
                'nama_operasi' => $item['nama_operasi'],
                'waktu_standar_menit' => round((float) ($item['waktu_standar_menit'] ?? 0), 2),
                'catatan' => $item['catatan'] ?? null,
            ]);

            if ($baris->pluck('urutan')->duplicates()->isNotEmpty()) {
                throw new RuntimeException('Nomor urut operasi tidak boleh kembar dalam satu BOM.');
            }

            if ($baris->contains(fn ($item) => $item['urutan'] < 1)) {
                throw new RuntimeException('Nomor urut operasi dimulai dari 1.');
            }

            $nonaktif = PusatKerja::whereIn('id', $baris->pluck('pusat_kerja_id'))
                ->where('status', PusatKerja::NONAKTIF)
                ->pluck('kode');

            if ($nonaktif->isNotEmpty()) {
                throw new RuntimeException('Pusat kerja nonaktif tidak dapat dipakai: '.$nonaktif->implode(', ').'.');
            }

            $bom->operasi()->delete();

            $baris->sortBy('urutan')->each(fn ($item) => OperasiBom::create($item + ['bom_id' => $bom->id]));

            return $bom->fresh('operasi.pusatKerja');
        });
    }

    public function routing(DataPesanan $pesanan): array
    {
        $bom = $this->bom->bomUntuk($pesanan);

        if (! $bom) {
            return ['bom' => null, 'basis' => 0.0, 'operasi' => collect(), 'total_menit' => 0.0];
        }

        $basis = $pesanan->sudahSelesai()
            ? (float) $pesanan->jumlah_selesai
            : (float) $pesanan->jumlah_rencana;

        $perUnit = max((float) $bom->jumlah_hasil, 0.000001);

        $operasi = $bom->operasi()->with('pusatKerja')->orderBy('urutan')->get()
            ->map(fn (OperasiBom $o) => [
                'urutan' => (int) $o->urutan,
                'nama_operasi' => $o->nama_operasi,
                'pusat_kerja' => $o->pusatKerja?->nama ?? '-',
                'pusat_kerja_id' => (int) $o->pusat_kerja_id,
                'waktu_standar_menit' => (float) $o->waktu_standar_menit,
                'total_menit' => round((float) $o->waktu_standar_menit * $basis / $perUnit, 2),
                'catatan' => $o->catatan,
            ]);

        return [
            'bom' => $bom,
            'basis' => $basis,
            'operasi' => $operasi,
            'total_menit' => round($operasi->sum('total_menit'), 2),
        ];
    }

    public function bebanPusatKerja(): Collection
    {
        $berjalan = DataPesanan::berjalan()->get();
        $bomPerBahan = Bom::aktif()
            ->with('operasi.pusatKerja')
            ->whereIn('bahan_id', $berjalan->pluck('bahan_hasil_id')->unique())
            ->orderBy('id')
            ->get()
            ->keyBy('bahan_id');

        $beban = [];

        foreach ($berjalan as $pesanan) {
            $bom = $bomPerBahan->get($pesanan->bahan_hasil_id);

            if (! $bom) {
                continue;
            }

            $basis = $pesanan->sudahSelesai()
                ? (float) $pesanan->jumlah_selesai
                : (float) $pesanan->jumlah_rencana;
            $perUnit = max((float) $bom->jumlah_hasil, 0.000001);
            $operasiPesanan = $bom->operasi->sortBy('urutan')->map(fn (OperasiBom $operasi) => [
                'pusat_kerja_id' => (int) $operasi->pusat_kerja_id,
                'pusat_kerja' => $operasi->pusatKerja?->nama ?? '-',
                'total_menit' => round((float) $operasi->waktu_standar_menit * $basis / $perUnit, 2),
            ]);

            foreach ($operasiPesanan as $operasi) {
                $id = $operasi['pusat_kerja_id'];
                $beban[$id] ??= ['pusat_kerja_id' => $id, 'pusat_kerja' => $operasi['pusat_kerja'], 'menit' => 0.0, 'perintah_kerja' => 0];
                $beban[$id]['menit'] += $operasi['total_menit'];
            }

            foreach (collect($operasiPesanan)->pluck('pusat_kerja_id')->unique() as $id) {
                $beban[$id]['perintah_kerja']++;
            }
        }

        return PusatKerja::aktif()->orderBy('kode')->get()->map(function (PusatKerja $pusat) use ($beban) {
            $baris = $beban[$pusat->id] ?? null;
            $menit = round($baris['menit'] ?? 0, 2);
            $kapasitas = $pusat->kapasitas_menit_per_hari ? (float) $pusat->kapasitas_menit_per_hari : null;

            return [
                'pusat_kerja' => $pusat,
                'menit' => $menit,
                'perintah_kerja' => $baris['perintah_kerja'] ?? 0,
                'kapasitas_harian' => $kapasitas,
                'hari_kerja' => $kapasitas ? round($menit / $kapasitas, 2) : null,
            ];
        });
    }
}
