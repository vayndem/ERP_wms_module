<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasLampiran;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FakturPenjualan extends Model
{
    use Auditable, HasLampiran;

    public const DRAFT = 'DRAFT';
    public const POSTED = 'POSTED';
    public const PARTIALLY_PAID = 'PARTIALLY_PAID';
    public const PAID = 'PAID';
    public const VOID = 'VOID';

    protected $table = 'wms_faktur_penjualan';

    protected $fillable = [
        'nomor', 'tanggal', 'jatuh_tempo', 'pelanggan_id', 'sales_user_id', 'no_faktur_pajak',
        'tanggal_faktur_pajak', 'ppnbm',
        'pembeli_nama', 'pembeli_npwp', 'pembeli_nik', 'pembeli_nitku', 'pembeli_alamat',
        'is_ppn', 'tarif_ppn', 'total_dpp', 'total_ppn', 'grand_total', 'sisa_tagihan',
        'status', 'keterangan', 'journal_id', 'dibuat_oleh', 'diposting_oleh', 'diposting_pada',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'jatuh_tempo' => 'date',
        'is_ppn' => 'boolean',
        'tarif_ppn' => 'decimal:4',
        'total_dpp' => 'decimal:2',
        'total_ppn' => 'decimal:2',
        'ppnbm' => 'decimal:2',
        'tanggal_faktur_pajak' => 'date',
        'grand_total' => 'decimal:2',
        'sisa_tagihan' => 'decimal:2',
        'diposting_pada' => 'datetime',
    ];

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'pelanggan_id');
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(FakturPenjualanDetail::class, 'faktur_penjualan_id');
    }

    public function pembayaran(): HasMany
    {
        return $this->hasMany(PenerimaanPembayaran::class, 'faktur_penjualan_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isTertagih(): bool
    {
        return in_array($this->status, [self::POSTED, self::PARTIALLY_PAID], true);
    }

    public static function statusPembayaran(float $grandTotal, float $dibayar): string
    {
        if ($dibayar + 0.005 >= $grandTotal) {
            return self::PAID;
        }

        return $dibayar > 0.005 ? self::PARTIALLY_PAID : self::POSTED;
    }
}
