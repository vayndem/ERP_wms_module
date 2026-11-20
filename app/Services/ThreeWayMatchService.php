<?php

namespace App\Services;

use App\Models\FakturPembelian;
use Illuminate\Support\Facades\Auth;

class ThreeWayMatchService
{
    public function evaluate(FakturPembelian $invoice, float $priceTolerancePercent = 0.5, float $quantityTolerancePercent = 0, ?int $userId = null): array
    {
        $invoice->loadMissing('receipts.lpb.details.bahan', 'receipts.lpb.serviceDetails.servicePoDetail', 'receipts.lpb.pembelian.details');
        $issues = [];
        $receiptValue = 0.0;

        foreach ($invoice->receipts as $receipt) {
            $lpb = $receipt->lpb;
            foreach ($lpb->details as $received) {
                $ordered = $lpb->pembelian?->details->firstWhere('bahan_id', $received->id_bahan);
                if (!$ordered) {
                    $issues[] = ['type' => 'MISSING_PO_LINE', 'lpb' => $lpb->id_lpb, 'material' => $received->bahan?->nama];
                    continue;
                }
                $qtyVariance = (float) $ordered->jumlah > 0
                    ? max(0, ((float) $received->jumlah_barang_diterima - (float) $ordered->jumlah) / (float) $ordered->jumlah * 100)
                    : 100;
                $priceVariance = (float) $ordered->harga > 0
                    ? abs((float) $received->harga - (float) $ordered->harga) / (float) $ordered->harga * 100
                    : 100;
                if ($qtyVariance > $quantityTolerancePercent + 0.000001) {
                    $issues[] = ['type' => 'QUANTITY_VARIANCE', 'lpb' => $lpb->id_lpb, 'material' => $received->bahan?->nama, 'variance_percent' => round($qtyVariance, 4)];
                }
                if ($priceVariance > $priceTolerancePercent + 0.000001) {
                    $issues[] = ['type' => 'PRICE_VARIANCE', 'lpb' => $lpb->id_lpb, 'material' => $received->bahan?->nama, 'variance_percent' => round($priceVariance, 4)];
                }
                $receiptValue += (float) $received->jumlah_barang_diterima * (float) $received->harga;
            }
            foreach ($lpb->serviceDetails as $service) {
                if (!$service->servicePoDetail) $issues[] = ['type' => 'MISSING_PO_LINE', 'lpb' => $lpb->id_lpb, 'material' => 'Jasa'];
                $receiptValue += (float) $service->amount;
            }
        }

        if (abs($receiptValue - (float) $invoice->sub_total) > 0.01) {
            $issues[] = ['type' => 'INVOICE_VALUE_VARIANCE', 'receipt_value' => round($receiptValue, 2), 'invoice_subtotal' => (float) $invoice->sub_total];
        }

        $status = collect($issues)->contains(fn ($issue) => in_array($issue['type'], ['MISSING_PO_LINE', 'INVOICE_VALUE_VARIANCE'], true))
            ? 'BLOCKED'
            : ($issues ? 'WARNING' : 'MATCHED');
        $summary = ['status' => $status, 'issues' => $issues, 'receipt_value' => round($receiptValue, 2)];
        $invoice->update(['match_status' => $status, 'match_summary' => $summary, 'matched_by' => $userId ?? Auth::id(), 'matched_at' => now()]);

        return $summary;
    }
}
