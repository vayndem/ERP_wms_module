<?php

namespace App\Services;

use App\Models\AlokasiTransferGudang;
use App\Models\BiayaTambahanAlokasi;
use App\Models\LayerPersediaan;
use App\Models\TransferGudang;
use App\Models\PemakaianBarangAlokasiStok;
use App\Models\PenerimaanBarang;
use App\Models\PenerimaanBarangDetail;
use App\Models\ReturPembelianDetail;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LacakPembelianService
{
    private const BATAS_TELUSUR = 10;

    public function telusuri(PenerimaanBarang $lpb): array
    {
        $lpb->loadMissing(['details.bahan', 'details.kategori', 'gudang', 'pembelian.supplier']);

        $baris = $lpb->details->map(fn (PenerimaanBarangDetail $detail) => $this->telusuriBaris($detail));

        $jumlahkan = fn (string $kolom) => round($baris->sum($kolom), 2);

        return [
            'lpb' => $lpb,
            'baris' => $baris,
            'total_nilai_pembelian' => $jumlahkan('nilai_pembelian'),
            'total_biaya_tambahan' => $jumlahkan('biaya_tambahan'),
            'total_nilai_masuk' => $jumlahkan('nilai_masuk'),
            'total_sisa_stok' => $jumlahkan('sisa_stok'),
            'total_beban_npk' => $jumlahkan('beban_npk'),
            'total_selisih_opname' => $jumlahkan('selisih_opname'),
            'total_terjual' => $jumlahkan('terjual'),
            'total_retur' => $jumlahkan('retur'),
            'total_hilang_transit' => $jumlahkan('hilang_transit'),
            'total_tidak_terlacak' => $jumlahkan('tidak_terlacak'),
        ];
    }

    private function telusuriBaris(PenerimaanBarangDetail $detail): array
    {
        $layers = $this->himpunanLayer($detail);
        $layerIds = $layers->pluck('id')->all();

        $nilaiPembelian = round((float) $detail->jumlah_barang_diterima * (float) $detail->harga, 2);
        $biayaTambahan = $this->biayaTambahan($layerIds);
        $sisaStok = $this->sisaStok($layers);
        $bebanNpk = $this->bebanNpk($layerIds);
        $selisihOpname = $this->selisihOpname($layerIds);
        $terjual = $this->terjual($layerIds);
        $retur = $this->retur($detail);
        $hilangTransit = $this->hilangTransit($layerIds);

        $nilaiMasuk = round($nilaiPembelian + $biayaTambahan, 2);
        $terlacak = round($sisaStok + $bebanNpk + $selisihOpname + $terjual + $retur + $hilangTransit, 2);

        return [
            'detail' => $detail,
            'bahan' => $detail->bahan,
            'kategori' => $detail->kategori,
            'jumlah' => (float) $detail->jumlah_barang_diterima,
            'harga' => (float) $detail->harga,
            'nilai_pembelian' => $nilaiPembelian,
            'biaya_tambahan' => $biayaTambahan,
            'nilai_masuk' => $nilaiMasuk,
            'sisa_stok' => $sisaStok,
            'beban_npk' => $bebanNpk,
            'selisih_opname' => $selisihOpname,
            'terjual' => $terjual,
            'retur' => $retur,
            'hilang_transit' => $hilangTransit,
            'tidak_terlacak' => round($nilaiMasuk - $terlacak, 2),
            'sebaran_gudang' => $this->sebaranGudang($layers),
            'jumlah_layer' => count($layerIds),
        ];
    }

    private function himpunanLayer(PenerimaanBarangDetail $detail): Collection
    {
        $ids = LayerPersediaan::where('source_type', 'LPB_DETAIL')
            ->where('source_id', $detail->id)
            ->pluck('id')
            ->all();

        $ids = array_values(array_unique(array_merge(
            $ids,
            LayerPersediaan::where('source_type', 'like', 'QC_REJECT_%')
                ->where('source_id', $detail->id)
                ->pluck('id')
                ->all()
        )));

        if (empty($ids)) {
            return collect();
        }

        for ($putaran = 0; $putaran < self::BATAS_TELUSUR; $putaran++) {
            $consider = LayerPersediaan::where('source_type', 'like', 'CONSIDER_%')
                ->whereIn('source_id', $ids)
                ->pluck('id')
                ->all();

            $transfer = AlokasiTransferGudang::whereIn('inventory_layer_asal_id', $ids)
                ->whereNotNull('inventory_layer_tujuan_id')
                ->pluck('inventory_layer_tujuan_id')
                ->all();

            $baru = array_diff(array_merge($consider, $transfer), $ids);

            if (empty($baru)) {
                break;
            }

            $ids = array_values(array_unique(array_merge($ids, $baru)));
        }

        return LayerPersediaan::with('gudang')->whereIn('id', $ids)->get();
    }

    private function sisaStok(Collection $layers): float
    {
        return round($layers
            ->reject(fn (LayerPersediaan $layer) => $layer->stock_status === 'REVERSED')
            ->sum(fn (LayerPersediaan $layer) => (float) $layer->remaining_quantity * (float) $layer->unit_cost), 2);
    }

    private function bebanNpk(array $layerIds): float
    {
        if (empty($layerIds)) {
            return 0.0;
        }

        return round((float) PemakaianBarangAlokasiStok::whereIn('inventory_layer_id', $layerIds)->sum('total_cost'), 2);
    }

    private function hilangTransit(array $layerIds): float
    {
        if (empty($layerIds)) {
            return 0.0;
        }

        $selisih = DB::table('alokasi_transfer_gudangs as a')
            ->join('wms_layer_persediaan as tujuan', 'tujuan.id', '=', 'a.inventory_layer_tujuan_id')
            ->join('detail_transfer_gudangs as d', 'd.id', '=', 'a.detail_transfer_gudang_id')
            ->join('transfer_gudangs as t', 't.id', '=', 'd.transfer_gudang_id')
            ->whereIn('a.inventory_layer_asal_id', $layerIds)
            ->where('t.status', TransferGudang::DITERIMA)
            ->selectRaw('COALESCE(SUM(a.total_nilai - (tujuan.initial_quantity * tujuan.unit_cost)), 0) as nilai')
            ->value('nilai');

        return round(max((float) $selisih, 0.0), 2);
    }

    private function terjual(array $layerIds): float
    {
        if (empty($layerIds)) {
            return 0.0;
        }

        return round((float) DB::table('wms_surat_jalan_alokasi')
            ->whereIn('inventory_layer_id', $layerIds)
            ->sum('total_hpp'), 2);
    }

    private function selisihOpname(array $layerIds): float
    {
        if (empty($layerIds)) {
            return 0.0;
        }

        return round((float) DB::table('wms_stock_opname_alokasi')
            ->whereIn('inventory_layer_id', $layerIds)
            ->sum('total_cost'), 2);
    }

    private function biayaTambahan(array $layerIds): float
    {
        if (empty($layerIds)) {
            return 0.0;
        }

        return round((float) BiayaTambahanAlokasi::whereIn('inventory_layer_id', $layerIds)->sum('allocated_amount'), 2);
    }

    private function retur(PenerimaanBarangDetail $detail): float
    {
        return round((float) ReturPembelianDetail::where('lpb_detail_id', $detail->id)->sum('total_harga'), 2);
    }

    private function sebaranGudang(Collection $layers): Collection
    {
        return $layers
            ->reject(fn (LayerPersediaan $layer) => $layer->stock_status === 'REVERSED')
            ->filter(fn (LayerPersediaan $layer) => (float) $layer->remaining_quantity > 0)
            ->groupBy('gudang_id')
            ->map(fn (Collection $group) => [
                'gudang' => $group->first()->gudang,
                'jumlah' => round($group->sum(fn ($layer) => (float) $layer->remaining_quantity), 6),
                'nilai' => round($group->sum(fn ($layer) => (float) $layer->remaining_quantity * (float) $layer->unit_cost), 2),
            ])
            ->values();
    }
}
