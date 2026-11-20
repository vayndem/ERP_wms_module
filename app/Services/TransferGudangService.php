<?php

namespace App\Services;

use App\Models\AlokasiTransferGudang;
use App\Models\Bahan;
use App\Models\Gudang;
use App\Models\LayerPersediaan;
use App\Models\LokasiGudang;
use App\Models\TransferGudang;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TransferGudangService
{
    public function __construct(private StokGudangService $stok, private DocumentOperationGuard $operations, private WmsAccountingService $akuntansi) {}

    public function konfirmasi(TransferGudang $transfer): TransferGudang
    {
        return DB::transaction(function () use ($transfer) {
            $transfer = TransferGudang::with('details')->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== TransferGudang::DIAJUKAN) throw new RuntimeException('Hanya transfer diajukan yang dapat dikonfirmasi.');
            $this->operations->claim('TRANSFER_GUDANG', $transfer->id, 'SHIP');
            $asal = Gudang::lockForUpdate()->findOrFail($transfer->gudang_asal_id);
            $tujuan = Gudang::lockForUpdate()->findOrFail($transfer->gudang_tujuan_id);
            if (!$asal->aktif || !$tujuan->aktif) throw new RuntimeException('Gudang asal dan tujuan harus aktif.');
            if ($asal->jenis === Gudang::RUSAK) throw new RuntimeException('Barang yang sudah masuk Gudang Rusak tidak dapat ditransfer kembali.');
            if ($asal->jenis === Gudang::CONSIDER) throw new RuntimeException('Barang Consider hanya dapat keluar melalui pemeriksaan Consider.');
            if ($tujuan->jenis === Gudang::RUSAK) throw new RuntimeException('Barang harus melalui Consider sebelum dinyatakan rusak.');
            if (!$asal->boleh_transfer || !$tujuan->boleh_transfer) throw new RuntimeException('Gudang tidak mengizinkan transfer.');

            foreach ($transfer->details as $detail) {
                $this->stok->saldo($asal->id, $detail->bahan_id);
                $allocations = $this->stok->ambilLayer($asal->id, $detail->bahan_id, (float) $detail->jumlah, $transfer->tanggal);
                $total = collect($allocations)->sum(fn($a) => $a['jumlah'] * $a['harga']);
                $average = (float) $detail->jumlah > 0 ? $total / (float) $detail->jumlah : 0;
                $this->stok->keluar($asal->id, $detail->bahan_id, (float) $detail->jumlah, $average, 'TRANSFER_KELUAR', 'TRANSFER_GUDANG', $transfer->id, $transfer->nomor_transfer, false);
                foreach ($allocations as $allocation) {
                    $alokasi = AlokasiTransferGudang::create([
                        'detail_transfer_gudang_id' => $detail->id,
                        'inventory_layer_asal_id' => $allocation['layer']->id,
                        'jumlah' => $allocation['jumlah'],
                        'harga_satuan' => $allocation['harga'],
                        'total_nilai' => round($allocation['jumlah'] * $allocation['harga'], 2),
                    ]);
                    $layerTujuan = LayerPersediaan::create([
                        'bahan_id' => $detail->bahan_id,
                        'gudang_id' => $tujuan->id,
                        'source_type' => 'TRANSFER_ALLOCATION',
                        'source_id' => $alokasi->id,
                        'transaction_date' => $transfer->tanggal,
                        'initial_quantity' => $allocation['jumlah'],
                        'remaining_quantity' => $allocation['jumlah'],
                        'unit_cost' => $allocation['harga'],
                        'stock_status' => 'IN_TRANSIT',
                    ]);
                    $alokasi->update(['inventory_layer_tujuan_id' => $layerTujuan->id]);
                }
                $detail->update(['jumlah_dikirim' => $detail->jumlah]);
            }
            $transfer->update(['status' => TransferGudang::DIKIRIM, 'dikirim_oleh' => Auth::id(), 'dikirim_pada' => now()]);
            $this->akuntansi->postPengirimanTransfer($transfer->fresh('details.bahan.tipeBarang'));
            return $transfer->fresh('details');
        });
    }

    public function terima(TransferGudang $transfer, array $received = [], ?string $notes = null, array $locations = []): TransferGudang
    {
        return DB::transaction(function () use ($transfer, $received, $notes, $locations) {
            $transfer = TransferGudang::with('details.alokasi')->lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== TransferGudang::DIKIRIM) throw new RuntimeException('Hanya transfer dalam perjalanan yang dapat diterima.');
            $this->operations->claim('TRANSFER_GUDANG', $transfer->id, 'RECEIVE');
            $tujuan = Gudang::lockForUpdate()->findOrFail($transfer->gudang_tujuan_id);
            $lokasi = LokasiGudang::whereIn('id', array_unique(array_filter(array_values($locations))))->get()->keyBy('id');

            foreach ($transfer->details as $detail) {
                $quantity = array_key_exists($detail->id, $received) ? (float) $received[$detail->id] : (float) $detail->jumlah_dikirim;
                if ($quantity < 0 || $quantity > (float) $detail->jumlah_dikirim) throw new RuntimeException('Jumlah diterima tidak valid.');
                $allocations = $detail->alokasi;
                $sentValue = (float) $allocations->sum('total_nilai');
                $average = (float) $detail->jumlah_dikirim > 0 ? $sentValue / (float) $detail->jumlah_dikirim : 0;
                $tujuanLokasi = $lokasi->get($locations[$detail->id] ?? null);
                if ($tujuanLokasi) {
                    $tujuanLokasi->assertMilikGudang((int) $tujuan->id);
                    $tujuanLokasi->assertMuat($quantity, $detail->bahan);
                }

                $remaining = $quantity;
                foreach ($allocations as $allocation) {
                    $layer = LayerPersediaan::lockForUpdate()->findOrFail($allocation->inventory_layer_tujuan_id);
                    $release = min($remaining, (float) $layer->remaining_quantity);
                    if ($release > 0) $layer->update(['remaining_quantity' => $release, 'initial_quantity' => $release, 'warehouse_location_id' => $tujuanLokasi?->id, 'stock_status' => $tujuan->jenis === Gudang::CONSIDER ? 'QC_HOLD' : 'AVAILABLE']);
                    else $layer->update(['remaining_quantity' => 0, 'initial_quantity' => 0, 'stock_status' => 'TRANSFER_SHORTAGE']);
                    $remaining -= $release;
                }
                if ($quantity > 0) $this->stok->masuk($tujuan->id, $detail->bahan_id, $quantity, $average, $tujuan->jenis === Gudang::CONSIDER ? 'CONSIDER_MASUK' : 'TRANSFER_MASUK', 'TRANSFER_GUDANG', $transfer->id, $transfer->nomor_transfer, false);
                $selisih = round((float) $detail->jumlah_dikirim - $quantity, 6);
                if ($selisih > 0) Bahan::whereKey($detail->bahan_id)->decrement('stok_onhand', $selisih);
                $detail->update(['jumlah_diterima' => $quantity, 'jumlah_selisih' => $selisih]);
            }
            $tanggalTerima = now();
            $transfer->update(['status' => TransferGudang::DITERIMA, 'diterima_oleh' => Auth::id(), 'diterima_pada' => $tanggalTerima, 'catatan_penerimaan' => $notes, 'dikonfirmasi_oleh' => Auth::id(), 'dikonfirmasi_pada' => $tanggalTerima]);
            $this->akuntansi->postPenerimaanTransfer($transfer, $tanggalTerima->toDateString());
            return $transfer->fresh('details');
        });
    }
}
