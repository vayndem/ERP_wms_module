<?php

namespace App\Services;

use App\Models\GelombangPengambilan;
use App\Models\PenerimaanBarang;
use App\Models\PesananPengambilan;
use App\Models\StockOpname;
use App\Models\TransferGudang;
use App\Models\User;
use Illuminate\Support\Collection;

class AntreanKerjaService
{
    public function __construct(private CycleCountService $siklus) {}

    public function untuk(User $user): array
    {
        $gudangIds = $user->accessibleGudangIds();

        if ($gudangIds === []) {
            return [
                'gudang_ids' => [],
                'antrean' => collect(),
                'gelombang_saya' => collect(),
                'jadwal_siklus' => collect(),
                'ringkasan' => [],
            ];
        }

        $antrean = $this->antreanUntukGudang($gudangIds);

        return [
            'gudang_ids' => $gudangIds,
            'antrean' => $antrean,
            'gelombang_saya' => $this->gelombangSaya($user, $gudangIds),
            'jadwal_siklus' => $this->siklus->papanJadwal($gudangIds),
            'ringkasan' => $antrean->groupBy('jenis')->map->count()->all(),
        ];
    }

    public function antreanUtama(User $user, int $limit = 10): Collection
    {
        $gudangIds = $user->accessibleGudangIds();

        return $gudangIds === []
            ? collect()
            : $this->antreanUntukGudang($gudangIds)->take($limit)->values();
    }

    private function antreanUntukGudang(array $gudangIds): Collection
    {
        return collect()
            ->merge($this->qcMenunggu($gudangIds))
            ->merge($this->putawayMenunggu($gudangIds))
            ->merge($this->pickMenunggu($gudangIds))
            ->merge($this->transferMenunggu($gudangIds))
            ->merge($this->opnameBerjalan($gudangIds))
            ->sortBy('urutan')
            ->values();
    }

    private function baris(string $jenis, string $label, string $judul, string $detail, string $url, int $urutan, string $tone): array
    {
        return compact('jenis', 'label', 'judul', 'detail', 'url', 'urutan', 'tone');
    }

    private function qcMenunggu(array $gudangIds): Collection
    {
        return PenerimaanBarang::with('gudang')
            ->where('document_type', 'GOODS')
            ->whereIn('gudang_id', $gudangIds)
            ->where('receiving_status', 'RECEIVED')
            ->latest('tanggal')->limit(50)->get()
            ->map(fn ($lpb) => $this->baris(
                'QC', 'Pemeriksaan QC', $lpb->id_lpb,
                ($lpb->gudang->nama ?? '-') . ' · ' . optional($lpb->tanggal)->format('d-m-Y'),
                route('wms-control.index'), 1, 'warning',
            ));
    }

    private function putawayMenunggu(array $gudangIds): Collection
    {
        return PenerimaanBarang::with('gudang')
            ->where('document_type', 'GOODS')
            ->whereIn('gudang_id', $gudangIds)
            ->whereIn('receiving_status', ['QC_COMPLETED', 'QC_COMPLETED_WITH_HOLD'])
            ->latest('tanggal')->limit(50)->get()
            ->map(fn ($lpb) => $this->baris(
                'PUTAWAY', 'Penempatan ke bin', $lpb->id_lpb,
                ($lpb->gudang->nama ?? '-') . ' · ' . ($lpb->receiving_status === 'QC_COMPLETED_WITH_HOLD' ? 'ada QC hold' : 'siap putaway'),
                route('wms-control.index'), 2, 'info',
            ));
    }

    private function pickMenunggu(array $gudangIds): Collection
    {
        return PesananPengambilan::with('lines')
            ->whereIn('gudang_id', $gudangIds)
            ->whereIn('status', ['RELEASED', 'PICKING'])
            ->latest('id')->limit(50)->get()
            ->map(fn ($pick) => $this->baris(
                'PICK', 'Pengambilan barang', $pick->number,
                $pick->lines->count() . ' baris · ' . ($pick->gelombang_id ? 'dalam gelombang' : 'belum digelombangkan'),
                route('wms-control.index'), 3, 'primary',
            ));
    }

    private function transferMenunggu(array $gudangIds): Collection
    {
        return TransferGudang::with('gudangAsal', 'gudangTujuan')
            ->where('status', 'DIKIRIM')
            ->whereIn('gudang_tujuan_id', $gudangIds)
            ->latest('id')->limit(50)->get()
            ->map(fn ($transfer) => $this->baris(
                'TRANSFER', 'Terima transfer', $transfer->nomor_transfer,
                ($transfer->gudangAsal->nama ?? '-') . ' -> ' . ($transfer->gudangTujuan->nama ?? '-'),
                route('transfer-gudangs.index'), 4, 'secondary',
            ));
    }

    private function opnameBerjalan(array $gudangIds): Collection
    {
        return StockOpname::with('warehouse')
            ->whereIn('warehouse_id', $gudangIds)
            ->whereIn('status', [StockOpname::DRAFT, StockOpname::SUBMITTED, StockOpname::APPROVED])
            ->latest('id')->limit(20)->get()
            ->map(fn ($opname) => $this->baris(
                'OPNAME', 'Stock opname', $opname->number,
                ($opname->warehouse->nama ?? '-') . ' · ' . $opname->jenis . ' · ' . $opname->status,
                route('stock-opname.show', $opname), 5, 'accent',
            ));
    }

    private function gelombangSaya(User $user, array $gudangIds): Collection
    {
        return GelombangPengambilan::with('gudang')
            ->withCount('pesanan')
            ->whereIn('gudang_id', $gudangIds)
            ->whereIn('status', [GelombangPengambilan::DIRENCANAKAN, GelombangPengambilan::DIRILIS])
            ->where(fn ($query) => $query->whereNull('ditugaskan_ke')->orWhere('ditugaskan_ke', $user->id))
            ->latest('id')->limit(20)->get();
    }
}
