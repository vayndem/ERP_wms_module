<?php

namespace App\Services;

use App\Models\Bahan;
use App\Models\MaterialRequest;
use App\Models\MutasiStok;
use App\Models\PengaturanBahanGudang;
use App\Models\RequestDetail;
use App\Models\SaranPengisianUlang;
use App\Models\LayerPersediaan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReplenishmentService
{
    public function __construct(private DocumentNumberService $numbers) {}

    public function calculate(?int $warehouseId = null): int
    {
        $settings = PengaturanBahanGudang::where('aktif', true)->when($warehouseId, fn ($query) => $query->where('gudang_id', $warehouseId))->get();
        $belumTuntas = $this->permintaanBelumTuntas($warehouseId);

        foreach ($settings as $setting) {
            $suggestion = SaranPengisianUlang::firstOrNew([
                'gudang_id' => $setting->gudang_id,
                'bahan_id' => $setting->bahan_id,
                'calculated_at' => today(),
            ]);
            if ($suggestion->status === SaranPengisianUlang::REQUESTED) {
                continue;
            }
            $requestBerjalan = $belumTuntas->get($setting->gudang_id . ':' . $setting->bahan_id);
            $usage = (float) MutasiStok::where('gudang_id', $setting->gudang_id)->where('bahan_id', $setting->bahan_id)->where('tanggal', '>=', now()->subDays(30))->sum('jumlah_keluar');
            $daily = $usage / 30;
            $available = (float) LayerPersediaan::where('gudang_id', $setting->gudang_id)->where('bahan_id', $setting->bahan_id)->where('stock_status', 'AVAILABLE')->where('remaining_quantity', '>', 0)->where(function ($query) {
                $query->whereNull('inventory_lot_id')->orWhereHas('lot', fn ($lot) => $lot->where('blocked', false)->where(fn ($expiry) => $expiry->whereNull('expires_at')->orWhereDate('expires_at', '>=', today())));
            })->sum('remaining_quantity');
            $reorderPoint = max((float) $setting->titik_pemesanan, (float) $setting->stok_pengaman);
            $target = max((float) $setting->stok_maksimum, $reorderPoint);
            $suggested = $available <= $reorderPoint ? max(0, $target - $available) : 0;
            $suggestion->fill([
                'average_daily_usage' => $daily,
                'lead_time_days' => $daily > 0 ? (int) ceil($reorderPoint / $daily) : 0,
                'available_quantity' => $available,
                'suggested_quantity' => $suggested,
                'priority' => $available <= (float) $setting->stok_pengaman ? 'CRITICAL' : ($suggested > 0 ? 'HIGH' : 'NORMAL'),
                'status' => match (true) {
                    $suggested <= 0 => SaranPengisianUlang::NO_ACTION,
                    $requestBerjalan !== null => SaranPengisianUlang::REQUESTED,
                    default => SaranPengisianUlang::OPEN,
                },
                'material_request_id' => $requestBerjalan,
            ])->save();
        }
        return $settings->count();
    }

    private function permintaanBelumTuntas(?int $warehouseId = null)
    {
        return SaranPengisianUlang::query()
            ->whereNotNull('material_request_id')
            ->when($warehouseId, fn ($query) => $query->where('gudang_id', $warehouseId))
            ->whereHas('materialRequest', fn ($query) => $query->whereIn('status', [MaterialRequest::PENDING, MaterialRequest::APPROVED]))
            ->orderByDesc('id')
            ->get(['gudang_id', 'bahan_id', 'material_request_id'])
            ->keyBy(fn ($row) => $row->gudang_id . ':' . $row->bahan_id)
            ->map(fn ($row) => (int) $row->material_request_id);
    }

    public function createMaterialRequest(SaranPengisianUlang $suggestion, ?int $userId = null): MaterialRequest
    {
        return DB::transaction(function () use ($suggestion, $userId) {
            $suggestion = SaranPengisianUlang::lockForUpdate()->findOrFail($suggestion->id);
            if ($suggestion->status !== SaranPengisianUlang::OPEN) {
                throw new RuntimeException('Saran pengisian ulang ini sudah diproses.');
            }
            if ((float) $suggestion->suggested_quantity <= 0) {
                throw new RuntimeException('Saran tanpa jumlah usulan tidak dapat dijadikan request.');
            }

            $berjalan = SaranPengisianUlang::where('gudang_id', $suggestion->gudang_id)
                ->where('bahan_id', $suggestion->bahan_id)
                ->whereNotNull('material_request_id')
                ->whereHas('materialRequest', fn ($query) => $query->whereIn('status', [MaterialRequest::PENDING, MaterialRequest::APPROVED]))
                ->with('materialRequest:id,no_request')
                ->first();
            if ($berjalan) {
                throw new RuntimeException("Bahan ini masih punya request berjalan ({$berjalan->materialRequest->no_request}).");
            }

            $bahan = Bahan::findOrFail($suggestion->bahan_id);
            $request = MaterialRequest::create([
                'no_request' => $this->numbers->internal('REQ', 'PO'),
                'status' => MaterialRequest::PENDING,
                'requested_by' => $userId,
            ]);

            RequestDetail::create([
                'request_id' => $request->id,
                'bahan_id' => $bahan->id,
                'nama_barang' => $bahan->nama,
                'jumlah_minta' => $suggestion->suggested_quantity,
                'realisasi' => 0,
                'kategori' => $bahan->kategori,
                'satuan' => $bahan->satuan,
                'berat_kecil' => $bahan->berat_kecil ?: 1.00,
                'satuan_kecil' => $bahan->satuan_kecil,
                'tipe_gudang' => $suggestion->gudang_id,
                'tipe_barang' => $bahan->tipe_barang,
                'keterangan' => 'Saran pengisian ulang ' . $suggestion->calculated_at->format('d-m-Y'),
            ]);

            $suggestion->update([
                'status' => SaranPengisianUlang::REQUESTED,
                'material_request_id' => $request->id,
            ]);

            return $request;
        });
    }
}
