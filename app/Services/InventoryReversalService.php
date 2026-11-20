<?php

namespace App\Services;

use App\Models\PembalikanDokumen;
use App\Models\Bahan;
use App\Models\FakturPembelian;
use App\Models\LayerPersediaan;
use App\Models\PembayaranFaktur;
use App\Models\PenerimaanBarang;
use App\Models\PenerimaanBarangDetail;
use App\Models\PemakaianBarang;
use App\Models\PesananPembelianDetail;
use App\Models\ReturPembelian;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryReversalService
{
    public function __construct(
        private StokGudangService $stock,
        private WmsAccountingService $accounting,
        private DocumentNumberService $numbers,
        private AccountingPeriodService $periods,
        private DataPesananService $dataPesanan,
    ) {}

    public function reverseNpk(PemakaianBarang $npk, string $reason, ?int $userId = null): PembalikanDokumen
    {
        $this->periods->assertOpen(now(), 'Reversal NPK');
        return DB::transaction(function () use ($npk, $reason, $userId) {
            $actorId = $userId ?? Auth::id();
            $npk = PemakaianBarang::lockForUpdate()->findOrFail($npk->id);
            $this->assertNotReversed('NPK', $npk->id);
            if ($npk->status !== PemakaianBarang::POSTED) throw new RuntimeException('Hanya NPK posted yang dapat dibalik.');
            $unitCost = (float) $npk->harga_satuan;
            $quantity = (float) $npk->jumlah_stok > 0 ? (float) $npk->jumlah_stok : (float) $npk->jumlah;
            if ($quantity <= 0) throw new RuntimeException('Jumlah NPK tidak valid untuk reversal.');
            $pesananProduksi = $npk->data_pesanan_id ? \App\Models\DataPesanan::find($npk->data_pesanan_id) : null;
            if ($pesananProduksi) {
                $this->dataPesanan->hapusBiaya($pesananProduksi, \App\Models\DataPesananBiaya::NPK, (int) $npk->id);
            }
            $this->accounting->restoreStock($npk, false);
            $this->stock->masuk((int) $npk->id_gudang_asal, (int) $npk->id_barang, $quantity, $unitCost, 'REVERSAL_NPK', 'NPK', $npk->id, $reason, true, $actorId);
            $journal = $this->accounting->reverseAutomaticJournal('NPK', $npk->id, "Reversal NPK {$npk->kode}: {$reason}");
            $npk->update(['status' => PemakaianBarang::REVERSED]);
            return $this->record('NPK', $npk->id, $reason, $journal->id, $actorId);
        });
    }

    public function reverseLpb(PenerimaanBarang $lpb, string $reason, ?int $userId = null): PembalikanDokumen
    {
        $this->periods->assertOpen(now(), 'Reversal LPB');
        return DB::transaction(function () use ($lpb, $reason, $userId) {
            $actorId = $userId ?? Auth::id();
            $lpb = PenerimaanBarang::with('details')->lockForUpdate()->findOrFail($lpb->id);
            $this->assertNotReversed('LPB', $lpb->id);
            if ($lpb->status !== PenerimaanBarang::POSTED) throw new RuntimeException('Hanya LPB posted aktif yang dapat dibalik.');
            if ($lpb->invoiceReceipts()->exists()) throw new RuntimeException('LPB sudah ditagih. Void invoice terlebih dahulu.');

            foreach ($lpb->details as $detail) {
                $layer = LayerPersediaan::where('source_type', 'LPB_DETAIL')->where('source_id', $detail->id)->lockForUpdate()->firstOrFail();
                if (abs((float) $layer->initial_quantity - (float) $layer->remaining_quantity) > 0.000001) {
                    throw new RuntimeException("Stok LPB {$lpb->id_lpb} sudah digunakan dan tidak dapat dibalik langsung.");
                }
                $this->stock->keluar((int) $lpb->gudang_id, (int) $detail->id_bahan, (float) $detail->jumlah_barang_diterima, (float) $detail->harga, 'REVERSAL_LPB', 'LPB', $lpb->id, $reason, true, $actorId);
                $layer->update(['remaining_quantity' => 0, 'stock_status' => 'REVERSED']);
                $poLine = PesananPembelianDetail::where('no_po', $lpb->no_po)->where('bahan_id', $detail->id_bahan)->lockForUpdate()->first();
                if ($poLine) {
                    $poLine->decrement('diterima', min((float) $poLine->diterima, (float) $detail->jumlah_barang_diterima));
                    $poLine->refresh();
                    $restoreOrdered = min((float) $detail->jumlah_barang_diterima, max(0, (float) $poLine->jumlah - (float) $poLine->diterima));
                    if ($restoreOrdered > 0) {
                        Bahan::whereKey($detail->id_bahan)->increment('stok_onpurchase', $restoreOrdered);
                        $this->stock->tambahPesanan((int) $lpb->gudang_id, (int) $detail->id_bahan, $restoreOrdered);
                    }
                }
            }
            $journal = $this->accounting->reverseAutomaticJournal('LPB', $lpb->id, "Reversal LPB {$lpb->id_lpb}: {$reason}");
            $lpb->update(['cancelled_by' => $actorId, 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'status' => PenerimaanBarang::REVERSED]);
            return $this->record('LPB', $lpb->id, $reason, $journal->id, $actorId);
        });
    }

    public function reverseReturPembelian(ReturPembelian $retur, string $reason, ?int $userId = null): PembalikanDokumen
    {
        $this->periods->assertOpen(now(), 'Reversal retur pembelian');
        return DB::transaction(function () use ($retur, $reason, $userId) {
            $actorId = $userId ?? Auth::id();
            $retur = ReturPembelian::with('details')->lockForUpdate()->findOrFail($retur->id);
            $this->assertNotReversed('RETUR_PEMBELIAN', $retur->id);
            if ($retur->status !== ReturPembelian::POSTED) throw new RuntimeException('Hanya retur pembelian posted yang dapat dibalik.');
            if ($retur->advance_payment_id) {
                $advance = PembayaranFaktur::lockForUpdate()->findOrFail($retur->advance_payment_id);
                if ($advance->pemakaianUangMuka()->exists()) {
                    throw new RuntimeException('Uang muka dari retur ini sudah dipakai pada pembayaran lain; batalkan pembayaran yang memakai uang muka tersebut terlebih dahulu.');
                }
            }
            $lpb = PenerimaanBarang::lockForUpdate()->findOrFail($retur->lpb_id);

            foreach ($retur->details as $detail) {
                $lpbDetail = PenerimaanBarangDetail::lockForUpdate()->findOrFail($detail->lpb_detail_id);
                $layer = LayerPersediaan::where('source_type', 'LPB_DETAIL')->where('source_id', $lpbDetail->id)->lockForUpdate()->firstOrFail();
                $layer->update(['remaining_quantity' => (float) $layer->remaining_quantity + (float) $detail->jumlah_retur]);
                $lpbDetail->update([
                    'jumlah_retur' => max(0, (float) $lpbDetail->jumlah_retur - (float) $detail->jumlah_retur),
                    'jumlah_tersisa' => (float) $lpbDetail->jumlah_tersisa + (float) $detail->jumlah_retur,
                ]);
                $this->stock->masuk((int) $lpb->gudang_id, (int) $lpbDetail->id_bahan, (float) $detail->jumlah_retur, (float) $detail->harga, 'REVERSAL_RETUR_PEMBELIAN', 'RETUR_PEMBELIAN', $retur->id, $reason, true, $actorId);
            }

            if ($retur->invoice_id) {
                $invoice = FakturPembelian::lockForUpdate()->findOrFail($retur->invoice_id);
                $restoredGrandTotal = round((float) $invoice->grand_total + (float) $retur->hutang_reduction, 2);
                $restoredSisaTagihan = round((float) $invoice->sisa_tagihan + (float) $retur->hutang_reduction, 2);
                $invoice->update([
                    'grand_total' => $restoredGrandTotal,
                    'sisa_tagihan' => $restoredSisaTagihan,
                    'status' => FakturPembelian::paymentStatus($restoredGrandTotal, (float) $invoice->payments()->sum('total_transaksi_pengurang_hutang')),
                ]);
            }
            if ($retur->advance_payment_id) {
                PembayaranFaktur::whereKey($retur->advance_payment_id)->update([
                    'status' => PembayaranFaktur::VOID,
                    'voided_by' => $actorId,
                    'voided_at' => now(),
                    'void_reason' => "Retur pembelian {$retur->no_retur} dibalik: {$reason}",
                ]);
            }

            $journal = $this->accounting->reverseAutomaticJournal('RETUR_PEMBELIAN', $retur->id, "Reversal retur pembelian {$retur->no_retur}: {$reason}");
            $retur->update(['status' => ReturPembelian::REVERSED]);
            return $this->record('RETUR_PEMBELIAN', $retur->id, $reason, $journal->id, $actorId);
        });
    }

    private function assertNotReversed(string $type, int $id): void
    {
        if (PembalikanDokumen::where('document_type', $type)->where('document_id', $id)->exists()) throw new RuntimeException('Dokumen sudah pernah dibalik.');
    }

    private function record(string $type, int $id, string $reason, int $journalId, ?int $userId): PembalikanDokumen
    {
        return PembalikanDokumen::create(['number' => $this->numbers->internal('RVS', 'DOC'), 'document_type' => $type, 'document_id' => $id, 'reason' => $reason, 'reversal_journal_id' => $journalId, 'created_by' => $userId, 'posted_at' => now()]);
    }
}
