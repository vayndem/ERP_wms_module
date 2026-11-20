<?php

namespace App\Services;

use App\Models\Bahan;
use App\Models\LayerPersediaan;
use App\Models\MaterialRequest;
use App\Models\PemakaianBarang;
use App\Models\ReservasiPersediaan;
use App\Models\Gudang;
use App\Models\PenerimaanBarang;
use App\Models\PesananPengambilan;
use App\Models\PemeriksaanKualitas;
use App\Models\StokGudang;
use App\Models\LokasiGudang;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WarehouseExecutionService
{
    public function __construct(private StokGudangService $stock, private DocumentNumberService $numbers) {}

    public function reserve(int $warehouseId, int $materialId, float $quantity, ?string $referenceType = null, ?int $referenceId = null, ?int $userId = null): ReservasiPersediaan
    {
        return DB::transaction(function () use ($warehouseId, $materialId, $quantity, $referenceType, $referenceId, $userId) {
            if ($quantity <= 0) throw new RuntimeException('Jumlah reservasi harus lebih besar dari nol.');
            $balance = $this->stock->saldo($warehouseId, $materialId);
            if ((float) $balance->stok_dapat_dipakai + .000001 < $quantity) throw new RuntimeException('Stok available, tidak expired, dan tidak diblokir tidak mencukupi untuk reservasi.');
            $balance->increment('stok_direservasi', $quantity);
            return ReservasiPersediaan::create(['number' => $this->numbers->internal('RSV', 'STK'), 'gudang_id' => $warehouseId, 'bahan_id' => $materialId, 'quantity' => $quantity, 'reference_type' => $referenceType, 'reference_id' => $referenceId, 'created_by' => $userId ?? Auth::id()]);
        });
    }

    public function reservasiOtomatis(MaterialRequest $request): array
    {
        $request->loadMissing('details');
        $hasil = ['dikunci' => 0, 'sebagian' => 0, 'gagal' => 0, 'rincian' => []];

        foreach ($request->details as $detail) {
            $gudangId = (int) $detail->tipe_gudang;
            $bahanId = (int) $detail->bahan_id;
            $diminta = (float) $detail->jumlah_acc;

            if (!$gudangId || !$bahanId || $diminta <= 0) {
                continue;
            }

            $bebas = (float) $this->stock->saldo($gudangId, $bahanId)->stok_dapat_dipakai;
            $dikunci = round(min($diminta, max($bebas, 0)), 6);

            if ($dikunci <= 0) {
                $hasil['gagal']++;
                $hasil['rincian'][] = ['bahan_id' => $bahanId, 'diminta' => $diminta, 'dikunci' => 0.0];
                continue;
            }

            $this->reserve($gudangId, $bahanId, $dikunci, 'MATERIAL_REQUEST', (int) $request->id);

            $hasil[$dikunci + 0.000001 >= $diminta ? 'dikunci' : 'sebagian']++;
            $hasil['rincian'][] = ['bahan_id' => $bahanId, 'diminta' => $diminta, 'dikunci' => $dikunci];
        }

        return $hasil;
    }

    public function release(ReservasiPersediaan $reservation): void
    {
        DB::transaction(function () use ($reservation) {
            $reservation = ReservasiPersediaan::lockForUpdate()->findOrFail($reservation->id);
            if (!in_array($reservation->status, ['ACTIVE', 'PICKING', 'PICKED'], true)) throw new RuntimeException('Reservasi sudah tidak aktif.');
            $remaining = (float) $reservation->quantity;
            $balance = StokGudang::where('gudang_id', $reservation->gudang_id)->where('bahan_id', $reservation->bahan_id)->lockForUpdate()->firstOrFail();
            $balance->update(['stok_direservasi' => max(0, (float) $balance->stok_direservasi - $remaining)]);
            $reservation->update(['status' => 'RELEASED']);
        });
    }

    public function createPick(ReservasiPersediaan $reservation, ?int $userId = null): PesananPengambilan
    {
        return DB::transaction(function () use ($reservation, $userId) {
            $reservation = ReservasiPersediaan::lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->status !== 'ACTIVE') throw new RuntimeException('Reservasi tidak aktif.');
            $quantity = (float) $reservation->quantity - (float) $reservation->picked_quantity;
            $layers = LayerPersediaan::with('lot')->where('gudang_id', $reservation->gudang_id)->where('bahan_id', $reservation->bahan_id)->where('stock_status', 'AVAILABLE')->where('remaining_quantity', '>', 0)
                ->orderByRaw('CASE WHEN inventory_lot_id IS NULL THEN 1 ELSE 0 END')->orderByRaw('(SELECT expires_at FROM wms_lot_persediaan WHERE wms_lot_persediaan.id = wms_layer_persediaan.inventory_lot_id)')->orderBy('transaction_date')->lockForUpdate()->get();
            if ((float) $layers->sum('remaining_quantity') + .000001 < $quantity) throw new RuntimeException('Layer tersedia tidak mencukupi untuk picking.');
            $pick = PesananPengambilan::create(['number' => $this->numbers->internal('PCK', 'STK'), 'gudang_id' => $reservation->gudang_id, 'status' => 'RELEASED', 'created_by' => $userId ?? Auth::id()]);
            $remaining = $quantity;
            foreach ($layers as $layer) {
                if ($remaining <= 0) break;
                $take = min($remaining, (float) $layer->remaining_quantity);
                $pick->lines()->create(['inventory_reservation_id' => $reservation->id, 'inventory_layer_id' => $layer->id, 'warehouse_location_id' => $layer->warehouse_location_id, 'quantity_requested' => $take]);
                $remaining -= $take;
            }
            $reservation->update(['status' => 'PICKING']);
            return $pick;
        });
    }

    public function completePick(PesananPengambilan $pick): void
    {
        DB::transaction(function () use ($pick) {
            $pick = PesananPengambilan::with('lines')->lockForUpdate()->findOrFail($pick->id);
            if ($pick->status !== 'RELEASED') throw new RuntimeException('Picking order tidak dapat diselesaikan.');
            foreach ($pick->lines as $line) {
                $line->update(['quantity_picked' => $line->quantity_requested]);
                $reservation = ReservasiPersediaan::lockForUpdate()->findOrFail($line->inventory_reservation_id);
                $reservation->increment('picked_quantity', $line->quantity_requested);
                if ((float) $reservation->fresh()->picked_quantity + .000001 >= (float) $reservation->quantity) $reservation->update(['status' => 'PICKED']);
            }
            $pick->update(['status' => 'COMPLETED', 'picked_by' => Auth::id(), 'picked_at' => now()]);
        });
    }

    public function createDraftIssue(PesananPengambilan $pick): PemakaianBarang
    {
        return DB::transaction(function () use ($pick) {
            $pick = PesananPengambilan::with('lines')->lockForUpdate()->findOrFail($pick->id);
            if ($pick->status !== 'COMPLETED') throw new RuntimeException('Picking order harus selesai dulu sebelum diterbitkan jadi NPK.');
            if (PemakaianBarang::where('picking_order_id', $pick->id)->exists()) throw new RuntimeException('Picking order ini sudah punya NPK.');

            $reservationIds = $pick->lines->pluck('inventory_reservation_id')->filter()->unique();
            if ($reservationIds->count() !== 1) throw new RuntimeException('Picking order harus terhubung ke tepat satu reservasi.');
            $reservation = ReservasiPersediaan::findOrFail($reservationIds->first());

            $jumlahStok = (float) $pick->lines->sum('quantity_picked');
            if ($jumlahStok <= 0) throw new RuntimeException('Belum ada kuantitas yang dipetik pada picking order ini.');

            $bahan = Bahan::findOrFail($reservation->bahan_id);

            return PemakaianBarang::create([
                'kode' => $this->numbers->external('NPK'),
                'tanggal' => today(),
                'id_barang' => $bahan->id,
                'id_gudang_asal' => $pick->gudang_id,
                'jumlah' => $bahan->smallUnitEquivalent($jumlahStok) ?? $jumlahStok,
                'jumlah_stok' => $jumlahStok,
                'satuan_transaksi' => $bahan->hasSmallUnit() ? $bahan->satuan_kecil : $bahan->satuan,
                'status' => PemakaianBarang::DRAFT,
                'inventory_reservation_id' => $reservation->id,
                'picking_order_id' => $pick->id,
                'id_user' => Auth::id() ?? 0,
                'keterangan' => 'Dari picking order ' . $pick->number,
            ]);
        });
    }

    public function consumeReservation(ReservasiPersediaan $reservation, int $warehouseId, int $materialId, float $quantity): void
    {
        $reservation = ReservasiPersediaan::lockForUpdate()->findOrFail($reservation->id);
        if (!in_array($reservation->status, ['ACTIVE', 'PICKED'], true)) throw new RuntimeException('Reservasi tidak siap digunakan.');
        if ((int) $reservation->gudang_id !== $warehouseId || (int) $reservation->bahan_id !== $materialId || abs((float) $reservation->quantity - $quantity) > .000001) throw new RuntimeException('Gudang, bahan, atau jumlah NPK tidak sama dengan reservasi.');
        $balance = StokGudang::where('gudang_id', $warehouseId)->where('bahan_id', $materialId)->lockForUpdate()->firstOrFail();
        $balance->update(['stok_direservasi' => max(0, (float) $balance->stok_direservasi - $quantity)]);
        $reservation->update(['status' => 'CONSUMED']);
    }

    public function inspect(PenerimaanBarang $lpb, array $decisions): PemeriksaanKualitas
    {
        return DB::transaction(function () use ($lpb, $decisions) {
            $lpb = PenerimaanBarang::with('details')->lockForUpdate()->findOrFail($lpb->id);
            if (!in_array($lpb->receiving_status, ['RECEIVED', 'QC_COMPLETED_WITH_HOLD'], true)) {
                throw new RuntimeException('QC hanya dapat dijalankan sebelum putaway, atau sebagai pemeriksaan lanjutan atas penerimaan yang masih punya QC hold.');
            }
            $lanjutan = $lpb->receiving_status === 'QC_COMPLETED_WITH_HOLD';
            if ($lanjutan && $decisions === []) {
                throw new RuntimeException('Pemeriksaan lanjutan harus menyebutkan baris mana yang diperiksa ulang.');
            }
            $inspection = PemeriksaanKualitas::create(['number' => $this->numbers->internal('QCI', 'WH'), 'lpb_id' => $lpb->id, 'status' => 'COMPLETED', 'inspected_by' => Auth::id(), 'inspected_at' => now()]);
            $hasRejected = false;
            $diperiksa = 0;
            foreach ($lpb->details as $detail) {
                if ($lanjutan && !array_key_exists($detail->id, $decisions)) continue;
                $accepted = (float) data_get($decisions, $detail->id . '.accepted', $detail->jumlah_barang_diterima);
                $rejected = (float) $detail->jumlah_barang_diterima - $accepted;
                $hasRejected = $hasRejected || $rejected > 0;
                if ($accepted < 0 || $rejected < 0) throw new RuntimeException('Keputusan QC melebihi jumlah diterima.');
                $inspection->lines()->create(['lpb_detail_id' => $detail->id, 'quantity_received' => $detail->jumlah_barang_diterima, 'quantity_accepted' => $accepted, 'quantity_rejected' => $rejected, 'decision' => $rejected > 0 ? ($accepted > 0 ? 'PARTIAL' : 'REJECTED') : 'ACCEPTED', 'reason' => data_get($decisions, $detail->id . '.reason')]);
                $layer = LayerPersediaan::where('source_type', 'LPB_DETAIL')->where('source_id', $detail->id)->lockForUpdate()->firstOrFail();
                if ($lanjutan && ($layer->stock_status !== 'AVAILABLE' || (int) $layer->gudang_id !== (int) $lpb->gudang_id)) {
                    throw new RuntimeException('Baris ini sedang berstatus QC hold; selesaikan lewat Pemeriksaan Consider, bukan QC ulang.');
                }
                if (abs((float) $layer->initial_quantity - (float) $layer->remaining_quantity) > .000001) throw new RuntimeException('Barang yang sudah digunakan tidak dapat masuk pemeriksaan QC penerimaan.');
                $diperiksa++;
                $consider = null;
                if ($rejected > 0) {
                    $consider = Gudang::where('jenis', Gudang::CONSIDER)->where('aktif', true)->first();
                    if (!$consider) throw new RuntimeException('Gudang Consider aktif wajib tersedia untuk barang QC reject.');
                    $this->stock->keluar((int) $lpb->gudang_id, (int) $detail->id_bahan, $rejected, (float) $layer->unit_cost, 'QC_REJECT_KELUAR', 'QUALITY_INSPECTION', $inspection->id, 'QC penerimaan', false);
                    $this->stock->masuk((int) $consider->id, (int) $detail->id_bahan, $rejected, (float) $layer->unit_cost, 'QC_HOLD_MASUK', 'QUALITY_INSPECTION', $inspection->id, 'Menunggu pemeriksaan Consider', false);
                }
                if ($accepted > 0 && $rejected > 0) {
                    $layer->update(['initial_quantity' => $accepted, 'remaining_quantity' => $accepted, 'stock_status' => 'AVAILABLE']);
                    LayerPersediaan::create(['bahan_id' => $layer->bahan_id, 'gudang_id' => $consider->id, 'warehouse_location_id' => null, 'inventory_lot_id' => $layer->inventory_lot_id, 'stock_status' => 'QC_HOLD', 'source_type' => 'QC_REJECT_' . $inspection->id, 'source_id' => $detail->id, 'transaction_date' => $layer->transaction_date, 'initial_quantity' => $rejected, 'remaining_quantity' => $rejected, 'unit_cost' => $layer->unit_cost]);
                } else {
                    $layer->update(['gudang_id' => $rejected > 0 ? $consider->id : $layer->gudang_id, 'warehouse_location_id' => $rejected > 0 ? null : $layer->warehouse_location_id, 'stock_status' => $rejected > 0 ? 'QC_HOLD' : 'AVAILABLE']);
                }
            }
            if ($diperiksa === 0) throw new RuntimeException('Tidak ada baris yang dapat diperiksa ulang pada penerimaan ini.');
            $masihHold = LayerPersediaan::whereIn('source_id', $lpb->details->pluck('id'))
                ->where(fn ($query) => $query->where('source_type', 'LPB_DETAIL')->orWhere('source_type', 'like', 'QC_REJECT_%'))
                ->where('stock_status', 'QC_HOLD')->where('remaining_quantity', '>', 0)->exists();
            $lpb->update(['receiving_status' => ($hasRejected || $masihHold) ? 'QC_COMPLETED_WITH_HOLD' : 'QC_COMPLETED']);
            return $inspection;
        });
    }

    public function putaway(PenerimaanBarang $lpb, array $locationByDetail): void
    {
        DB::transaction(function () use ($lpb, $locationByDetail) {
            $lpb = PenerimaanBarang::with('details.bahan')->lockForUpdate()->findOrFail($lpb->id);
            $detailIds = $lpb->details->pluck('id');
            if (LayerPersediaan::where('source_type', 'LPB_DETAIL')->whereIn('source_id', $detailIds)->where('stock_status', 'QC_HOLD')->where('remaining_quantity', '>', 0)->exists()) throw new RuntimeException('Selesaikan keputusan barang QC hold sebelum putaway.');

            $locations = LokasiGudang::whereIn('id', array_unique(array_values($locationByDetail)))->get()->keyBy('id');

            foreach ($lpb->details as $detail) {
                $location = $locations->get($locationByDetail[$detail->id] ?? null);
                if (!$location) throw new RuntimeException("Baris {$detail->bahan?->nama} belum dipilihkan lokasi putaway.");
                $location->assertMilikGudang((int) $lpb->gudang_id);

                $masuk = (float) LayerPersediaan::where('source_type', 'LPB_DETAIL')->where('source_id', $detail->id)->sum('remaining_quantity');
                $location->assertMuat($masuk, $detail->bahan);

                LayerPersediaan::where('source_type', 'LPB_DETAIL')->where('source_id', $detail->id)->update(['warehouse_location_id' => $location->id]);
            }

            $lpb->update(['receiving_status' => 'PUTAWAY', 'putaway_by' => Auth::id(), 'putaway_at' => now()]);
        });
    }

}
