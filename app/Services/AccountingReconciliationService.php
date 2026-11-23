<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\DataPesanan;
use App\Models\FakturPembelian;
use App\Models\FakturPenjualan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountingReconciliationService
{
    public function checks(): Collection
    {
        $stock = DB::table('bahans')
            ->leftJoinSub(
                DB::table('wms_layer_persediaan')->select('bahan_id')
                    ->selectRaw('SUM(remaining_quantity) layer_quantity')
                    ->selectRaw('SUM(remaining_quantity * unit_cost) inventory_value')
                    ->groupBy('bahan_id'),
                'layers',
                'layers.bahan_id',
                '=',
                'bahans.id'
            )
            ->selectRaw('COUNT(*) total_rows')
            ->selectRaw('SUM(CASE WHEN ABS(COALESCE(bahans.stok_onhand,0)-COALESCE(layers.layer_quantity,0)) <= 0.000001 THEN 0 ELSE 1 END) invalid_rows')
            ->selectRaw('SUM(COALESCE(layers.inventory_value,0)) inventory_value')
            ->first();

        $journal = DB::table('wms_jurnal')->whereIn('status', ['POSTED', 'REVERSED'])
            ->selectRaw('COUNT(*) total_rows')
            ->selectRaw('SUM(CASE WHEN ABS(COALESCE(total_debit,0)-COALESCE(total_kredit,0)) <= 0.01 THEN 0 ELSE 1 END) invalid_rows')
            ->first();

        $invoice = DB::table('wms_faktur_pembelian')->where('status', '!=', FakturPembelian::VOID)
            ->where('status', '!=', FakturPembelian::PENDING_APPROVAL)
            ->selectRaw('COUNT(*) total_rows')
            ->selectRaw('SUM(CASE WHEN ABS(COALESCE(sisa_tagihan,0) - GREATEST(COALESCE(grand_total,0)-COALESCE(total_pembayaran,0),0)) <= 0.01 THEN 0 ELSE 1 END) invalid_rows')
            ->selectRaw('SUM(COALESCE(sisa_tagihan,0)) outstanding')
            ->first();

        $grniExpected = (float) DB::table('wms_penerimaan_barang_detail')
            ->join('wms_penerimaan_barang', 'wms_penerimaan_barang.id_lpb', '=', 'wms_penerimaan_barang_detail.id_lpb')
            ->leftJoin('wms_faktur_pembelian_penerimaan', 'wms_faktur_pembelian_penerimaan.lpb_id', '=', 'wms_penerimaan_barang.id')
            ->leftJoin('wms_faktur_pembelian', 'wms_faktur_pembelian.id', '=', 'wms_faktur_pembelian_penerimaan.invoice_lpb_id')
            ->where(fn($query) => $query->whereNull('wms_faktur_pembelian_penerimaan.id')
                ->orWhere('wms_faktur_pembelian.status', FakturPembelian::PENDING_APPROVAL))
            ->sum(DB::raw('wms_penerimaan_barang_detail.jumlah_barang_diterima * wms_penerimaan_barang_detail.harga'));
        $grniAccounts = DB::table('kategori_bahans')->whereNotNull('coa_clearing_lpb_id')
            ->distinct()->pluck('coa_clearing_lpb_id');
        $grniLedger = (float) DB::table('wms_jurnal_detail')->join('wms_jurnal', 'wms_jurnal.id', '=', 'wms_jurnal_detail.jurnal_id')
            ->whereIn('wms_jurnal.status', ['POSTED', 'REVERSED'])->whereIn('wms_jurnal_detail.coa_id', $grniAccounts)
            ->selectRaw('COALESCE(SUM(kredit-debit),0) balance')->value('balance');

        $apLedger = null;
        try {
            $apId = AccountingSetting::accountId(AccountingSetting::HUTANG_USAHA);
            $apLedger = (float) DB::table('wms_jurnal_detail')->join('wms_jurnal', 'wms_jurnal.id', '=', 'wms_jurnal_detail.jurnal_id')
                ->whereIn('wms_jurnal.status', ['POSTED', 'REVERSED'])->where('wms_jurnal_detail.coa_id', $apId)
                ->selectRaw('COALESCE(SUM(kredit-debit),0) balance')->value('balance');
        } catch (\RuntimeException) {
            $apLedger = null;
        }

        $wipLedger = null;
        try {
            $wipId = AccountingSetting::accountId(AccountingSetting::BARANG_DALAM_PROSES);
            $wipLedger = (float) DB::table('wms_jurnal_detail')->join('wms_jurnal', 'wms_jurnal.id', '=', 'wms_jurnal_detail.jurnal_id')
                ->whereIn('wms_jurnal.status', ['POSTED', 'REVERSED'])->where('wms_jurnal_detail.coa_id', $wipId)
                ->selectRaw('COALESCE(SUM(debit-kredit),0) balance')->value('balance');
        } catch (\RuntimeException) {
            $wipLedger = null;
        }

        $akumulasiRevaluasi = round((float) DB::table('wms_revaluasi_kurs')->sum('selisih'), 2);

        $arLedger = null;
        try {
            $arId = AccountingSetting::accountId(AccountingSetting::PIUTANG_USAHA);
            $arLedger = (float) DB::table('wms_jurnal_detail')->join('wms_jurnal', 'wms_jurnal.id', '=', 'wms_jurnal_detail.jurnal_id')
                ->whereIn('wms_jurnal.status', ['POSTED', 'REVERSED'])->where('wms_jurnal_detail.coa_id', $arId)
                ->selectRaw('COALESCE(SUM(debit-kredit),0) balance')->value('balance');
        } catch (\RuntimeException) {
            $arLedger = null;
        }

        $arSubledger = round((float) DB::table('wms_faktur_penjualan')
            ->whereIn('status', [FakturPenjualan::POSTED, FakturPenjualan::PARTIALLY_PAID])
            ->sum('sisa_tagihan'), 2);

        $transitLedger = null;
        try {
            $transitId = AccountingSetting::accountId(AccountingSetting::PERSEDIAAN_DALAM_PERJALANAN);
            $transitLedger = (float) DB::table('wms_jurnal_detail')->join('wms_jurnal', 'wms_jurnal.id', '=', 'wms_jurnal_detail.jurnal_id')
                ->whereIn('wms_jurnal.status', ['POSTED', 'REVERSED'])->where('wms_jurnal_detail.coa_id', $transitId)
                ->selectRaw('COALESCE(SUM(debit-kredit),0) balance')->value('balance');
        } catch (\RuntimeException) {
            $transitLedger = null;
        }

        $transitLayer = round((float) DB::table('wms_layer_persediaan')
            ->where('stock_status', 'IN_TRANSIT')
            ->selectRaw('COALESCE(SUM(remaining_quantity * unit_cost),0) nilai')
            ->value('nilai'), 2);

        $transitSelisih = round((float) DB::table('detail_transfer_gudangs as d')
            ->joinSub(
                DB::table('alokasi_transfer_gudangs')->select('detail_transfer_gudang_id')
                    ->selectRaw('SUM(total_nilai) nilai_kirim')
                    ->groupBy('detail_transfer_gudang_id'),
                'a',
                'a.detail_transfer_gudang_id',
                '=',
                'd.id'
            )
            ->where('d.jumlah_selisih', '>', 0)
            ->where('d.jumlah_dikirim', '>', 0)
            ->selectRaw('COALESCE(SUM(a.nilai_kirim / d.jumlah_dikirim * d.jumlah_selisih),0) nilai')
            ->value('nilai'), 2);

        $transitDiharapkan = round($transitLayer + $transitSelisih, 2);

        $wipPerintah = DataPesanan::saldoWipBatch(DataPesanan::berjalan()->get());

        return collect([
            [
                'key' => 'stock',
                'label' => 'Stok on hand vs layer',
                'total' => (int) $stock->total_rows,
                'invalid' => (int) $stock->invalid_rows,
                'amount' => (float) $stock->inventory_value,
                'expected' => null,
            ],
            [
                'key' => 'journal',
                'label' => 'Keseimbangan jurnal',
                'total' => (int) $journal->total_rows,
                'invalid' => (int) $journal->invalid_rows,
                'amount' => null,
                'expected' => null,
            ],
            [
                'key' => 'invoice',
                'label' => 'Sisa tagihan invoice',
                'total' => (int) $invoice->total_rows,
                'invalid' => (int) $invoice->invalid_rows,
                'amount' => (float) $invoice->outstanding,
                'expected' => null,
            ],
            [
                'key' => 'grni',
                'label' => 'LPB Barang belum ditagih vs saldo GRNI',
                'total' => 1,
                'invalid' => abs($grniExpected - $grniLedger) <= .01 ? 0 : 1,
                'amount' => $grniLedger,
                'expected' => $grniExpected,
            ],
            [
                'key' => 'ap',
                'label' => 'Invoice belum lunas + revaluasi kurs vs hutang supplier',
                'total' => 1,
                'invalid' => $apLedger !== null && abs(((float) $invoice->outstanding + $akumulasiRevaluasi) - $apLedger) <= .01 ? 0 : 1,
                'amount' => $apLedger,
                'expected' => round((float) $invoice->outstanding + $akumulasiRevaluasi, 2),
            ],
            [
                'key' => 'ar',
                'label' => 'Faktur penjualan belum lunas vs piutang usaha',
                'total' => 1,
                'invalid' => $arLedger !== null && abs($arSubledger - $arLedger) <= .01 ? 0 : 1,
                'amount' => $arLedger,
                'expected' => $arSubledger,
            ],
            [
                'key' => 'transit',
                'label' => 'Persediaan dalam perjalanan vs layer in-transit',
                'total' => 1,
                'invalid' => $transitLedger !== null && abs($transitDiharapkan - $transitLedger) <= .01 ? 0 : 1,
                'amount' => $transitLedger,
                'expected' => $transitDiharapkan,
            ],
            [
                'key' => 'wip',
                'label' => 'Barang dalam proses vs perintah kerja berjalan',
                'total' => 1,
                'invalid' => $wipLedger !== null && abs($wipPerintah - $wipLedger) <= .01 ? 0 : 1,
                'amount' => $wipLedger,
                'expected' => $wipPerintah,
            ],
        ]);
    }
}
