<?php

namespace App\Services;

use App\Models\PengaturanBahanGudang;
use App\Models\StockOpname;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AbcAnalysisService
{
    public const AMBANG_A = 0.80;
    public const AMBANG_B = 0.95;
    public const JENDELA_BULAN = 12;

    public const SIKLUS_BULAN = ['A' => 1, 'B' => 3, 'C' => 12];

    public const LABEL = [
        'A' => 'Kelas A — nilai pemakaian tertinggi',
        'B' => 'Kelas B — menengah',
        'C' => 'Kelas C — nilai pemakaian terendah',
    ];

    public function hitung(?int $gudangId = null): int
    {
        $pemakaian = $this->nilaiPemakaian($gudangId);
        $tersentuh = 0;

        foreach ($pemakaian->groupBy('gudang_id') as $gudang => $baris) {
            $total = (float) $baris->sum('nilai');
            $kumulatif = 0.0;

            foreach ($baris->sortByDesc('nilai') as $row) {
                $rasioSebelum = $total > 0 ? $kumulatif / $total : 1.0;
                $kumulatif += (float) $row->nilai;
                $rasioSesudah = $total > 0 ? $kumulatif / $total : 1.0;

                PengaturanBahanGudang::updateOrCreate(
                    ['gudang_id' => (int) $gudang, 'bahan_id' => (int) $row->bahan_id],
                    [
                        'kelas_abc' => $this->kelas($rasioSebelum, (float) $row->nilai),
                        'nilai_pemakaian' => round((float) $row->nilai, 2),
                        'kontribusi_kumulatif' => round($rasioSesudah, 4),
                        'abc_dihitung_pada' => now(),
                    ],
                );
                $tersentuh++;
            }
        }

        return $tersentuh;
    }

    private function kelas(float $rasioSebelum, float $nilai): string
    {
        if ($nilai <= 0) {
            return 'C';
        }

        return match (true) {
            $rasioSebelum < self::AMBANG_A => 'A',
            $rasioSebelum < self::AMBANG_B => 'B',
            default => 'C',
        };
    }

    private function nilaiPemakaian(?int $gudangId): Collection
    {
        $sejak = now()->subMonths(self::JENDELA_BULAN)->startOfDay();

        return DB::table('wms_pemakaian_barang_alokasi_stok as a')
            ->join('wms_pemakaian_barang as n', 'n.id', '=', 'a.npk_id')
            ->join('wms_layer_persediaan as l', 'l.id', '=', 'a.inventory_layer_id')
            ->where('n.status', 'POSTED')
            ->where('n.tanggal', '>=', $sejak)
            ->when($gudangId, fn ($query) => $query->where('l.gudang_id', $gudangId))
            ->groupBy('l.gudang_id', 'l.bahan_id')
            ->select([
                'l.gudang_id',
                'l.bahan_id',
                DB::raw('SUM(a.total_cost) as nilai'),
            ])
            ->get();
    }

    public function jatuhTempoSiklus(int $gudangId): array
    {
        $jatuhTempo = [];

        foreach (self::SIKLUS_BULAN as $kelas => $bulan) {
            $jumlahBahan = PengaturanBahanGudang::where('gudang_id', $gudangId)
                ->where('kelas_abc', $kelas)
                ->where('aktif', true)
                ->count();

            if ($jumlahBahan === 0) {
                continue;
            }

            $terakhir = StockOpname::where('warehouse_id', $gudangId)
                ->where('jenis', StockOpname::SIKLUS)
                ->where('kelas_abc', $kelas)
                ->where('status', StockOpname::POSTED)
                ->max('posted_at');

            $terakhir = $terakhir ? Carbon::parse($terakhir) : null;
            $batas = now()->subMonths($bulan);
            $jatuhTempo[$kelas] = [
                'kelas' => $kelas,
                'bahan' => $jumlahBahan,
                'siklus_bulan' => $bulan,
                'terakhir' => $terakhir,
                'jatuh_tempo' => $terakhir === null || $terakhir < $batas,
                'hari_sejak' => $terakhir ? (int) $terakhir->diffInDays(now()) : null,
            ];
        }

        return $jatuhTempo;
    }
}
