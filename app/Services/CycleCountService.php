<?php

namespace App\Services;

use App\Models\PengaturanBahanGudang;
use App\Models\StockOpname;
use App\Models\StokGudang;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CycleCountService
{
    public function __construct(
        private AbcAnalysisService $abc,
        private DocumentNumberService $numbers,
        private AccountingPeriodService $periods,
    ) {}

    public function bahanUntukSiklus(int $gudangId, string $kelas): Collection
    {
        $bahanIds = PengaturanBahanGudang::where('gudang_id', $gudangId)
            ->where('kelas_abc', $kelas)
            ->where('aktif', true)
            ->pluck('bahan_id');

        if ($bahanIds->isEmpty()) {
            return collect();
        }

        return StokGudang::with('bahan')
            ->where('gudang_id', $gudangId)
            ->whereIn('bahan_id', $bahanIds)
            ->where('stok_tersedia', '>', 0)
            ->get();
    }

    public function mulai(int $gudangId, string $kelas, string $cutoff, ?string $catatan = null): StockOpname
    {
        if (! array_key_exists($kelas, AbcAnalysisService::SIKLUS_BULAN)) {
            throw new RuntimeException('Kelas ABC tidak dikenal. Gunakan A, B, atau C.');
        }

        $this->periods->assertOpen($cutoff, 'Cycle count');

        $saldo = $this->bahanUntukSiklus($gudangId, $kelas);

        if ($saldo->isEmpty()) {
            throw new RuntimeException("Tidak ada bahan kelas {$kelas} bersaldo di gudang ini. Jalankan perhitungan ABC lebih dulu.");
        }

        return DB::transaction(function () use ($gudangId, $kelas, $cutoff, $catatan, $saldo) {
            $terbuka = StockOpname::where('warehouse_id', $gudangId)
                ->whereIn('status', [StockOpname::DRAFT, StockOpname::SUBMITTED, StockOpname::APPROVED, StockOpname::REJECTED])
                ->exists();

            if ($terbuka) {
                throw new RuntimeException('Masih ada stock opname gudang ini yang belum selesai.');
            }

            $opname = StockOpname::create([
                'number' => $this->numbers->internal('OPS', 'STK'),
                'warehouse_id' => $gudangId,
                'jenis' => StockOpname::SIKLUS,
                'kelas_abc' => $kelas,
                'cutoff_at' => $cutoff,
                'status' => StockOpname::DRAFT,
                'notes' => $catatan ?? "Cycle count kelas {$kelas}",
                'created_by' => Auth::id(),
            ]);

            foreach ($saldo as $row) {
                $opname->details()->create([
                    'bahan_id' => $row->bahan_id,
                    'system_quantity' => $row->stok_tersedia,
                    'physical_quantity' => $row->stok_tersedia,
                    'difference_quantity' => 0,
                ]);
            }

            return $opname->fresh('details');
        });
    }

    public function papanJadwal(array $gudangIds): Collection
    {
        $jumlahBahan = PengaturanBahanGudang::query()
            ->whereIn('gudang_id', $gudangIds)
            ->whereIn('kelas_abc', array_keys(AbcAnalysisService::SIKLUS_BULAN))
            ->where('aktif', true)
            ->selectRaw('gudang_id, kelas_abc, COUNT(*) as jumlah')
            ->groupBy('gudang_id', 'kelas_abc')
            ->get()
            ->keyBy(fn ($row) => $row->gudang_id.':'.$row->kelas_abc);

        $terakhir = StockOpname::query()
            ->whereIn('warehouse_id', $gudangIds)
            ->where('jenis', StockOpname::SIKLUS)
            ->whereIn('kelas_abc', array_keys(AbcAnalysisService::SIKLUS_BULAN))
            ->where('status', StockOpname::POSTED)
            ->selectRaw('warehouse_id, kelas_abc, MAX(posted_at) as terakhir')
            ->groupBy('warehouse_id', 'kelas_abc')
            ->get()
            ->keyBy(fn ($row) => $row->warehouse_id.':'.$row->kelas_abc);

        return collect($gudangIds)->map(function ($gudangId) use ($jumlahBahan, $terakhir) {
            $kelas = collect(AbcAnalysisService::SIKLUS_BULAN)->mapWithKeys(function ($bulan, $namaKelas) use ($gudangId, $jumlahBahan, $terakhir) {
                $kunci = $gudangId.':'.$namaKelas;
                $jumlah = (int) ($jumlahBahan->get($kunci)?->jumlah ?? 0);

                if ($jumlah === 0) {
                    return [];
                }

                $tanggalTerakhir = $terakhir->get($kunci)?->terakhir;

                return [$namaKelas => [
                    'kelas' => $namaKelas,
                    'bahan' => $jumlah,
                    'siklus_bulan' => $bulan,
                    'terakhir' => $tanggalTerakhir,
                    'jatuh_tempo' => $tanggalTerakhir === null || $tanggalTerakhir < now()->subMonths($bulan),
                    'hari_sejak' => $tanggalTerakhir ? (int) now()->diffInDays($tanggalTerakhir) : null,
                ]];
            })->all();

            return ['gudang_id' => (int) $gudangId, 'kelas' => $kelas];
        })->filter(fn ($row) => $row['kelas'] !== [])->values();
    }
}
