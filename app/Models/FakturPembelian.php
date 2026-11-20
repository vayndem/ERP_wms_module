<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasLampiran;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FakturPembelian extends Model
{
    use HasFactory, Auditable, HasLampiran;

    public const PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const UNPAID = 'UNPAID';
    public const PARTIALLY_PAID = 'PARTIALLY_PAID';
    public const PAID = 'PAID';
    public const VOID = 'VOID';

    protected $table = 'wms_faktur_pembelian';

    protected $fillable = [
        'no_invoice',
        'kode_supplier',
        'tanggal',
        'tgl_deadline_pembayaran',
        'sub_total',
        'jenis_pajak',
        'dpp_ppn',
        'tarif_ppn',
        'ppn',
        'no_faktur_pajak',
        'tanggal_faktur_pajak',
        'ppnbm',
        'jenis_pph',
        'kode_objek_pajak',
        'dasar_pph',
        'tarif_pph',
        'diskon',
        'ongkir',
        'ppn_impor',
        'pph',
        'grand_total',
        'total_pembayaran',
        'sisa_tagihan',
        'note',
        'mata_uang_asing',
        'kurs',
        'nilai_asing',
        'status',
        'match_status',
        'match_summary',
        'matched_by',
        'matched_at',
        'voided_by',
        'voided_at',
        'void_reason',
        'approved_by',
        'approved_at',
    ];

    protected $appends = ['status_pembayaran'];

    public static function paymentStatus(float $grandTotal, float $totalPaid): string
    {
        if ($grandTotal > 0 && $totalPaid >= $grandTotal - 0.01) {
            return self::PAID;
        }

        return $totalPaid > 0 ? self::PARTIALLY_PAID : self::UNPAID;
    }

    public function getStatusPembayaranAttribute(): string
    {
        return match ($this->status) {
            self::PENDING_APPROVAL => 'Menunggu Persetujuan',
            self::PAID => 'Lunas',
            self::PARTIALLY_PAID => 'Dibayar Sebagian',
            self::VOID => 'Dibatalkan',
            default => 'Belum Dibayar',
        };
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'kode_supplier');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payments()
    {
        return $this->hasMany(PembayaranFaktur::class, 'invoice_lpb_id')
            ->where('status', PembayaranFaktur::POSTED);
    }

    public function receipts()
    {
        return $this->hasMany(FakturPembelianPenerimaan::class, 'invoice_lpb_id');
    }

    public function lpbs()
    {
        return $this->belongsToMany(PenerimaanBarang::class, 'wms_faktur_pembelian_penerimaan', 'invoice_lpb_id', 'lpb_id')
            ->withPivot('amount')->withTimestamps();
    }

    protected $casts = [
        'tanggal' => 'date',
        'tanggal_faktur_pajak' => 'date',
        'ppnbm' => 'decimal:2',
        'tgl_deadline_pembayaran' => 'date',
        'sub_total' => 'decimal:2',
        'ppn' => 'decimal:2',
        'dpp_ppn' => 'decimal:2',
        'tarif_ppn' => 'decimal:4',
        'dasar_pph' => 'decimal:2',
        'tarif_pph' => 'decimal:4',
        'grand_total' => 'decimal:2',
        'voided_at' => 'datetime',
        'match_summary' => 'array',
        'matched_at' => 'datetime',
    ];
}
