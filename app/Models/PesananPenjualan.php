<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PesananPenjualan extends Model
{
    use Auditable;

    public const OPEN = 'OPEN';
    public const CLOSED = 'CLOSED';
    public const DIBATALKAN = 'DIBATALKAN';

    protected $table = 'wms_pesanan_penjualan';

    protected $fillable = [
        'nomor', 'tanggal', 'pelanggan_id', 'sales_user_id', 'gudang_id', 'nomor_po_pelanggan',
        'is_ppn', 'tarif_ppn', 'total_dpp', 'total_ppn', 'grand_total',
        'status', 'keterangan', 'dibuat_oleh',
        'plafon_dilampaui', 'alasan_plafon', 'plafon_disetujui_oleh', 'eksposur_saat_dibuat',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'is_ppn' => 'boolean',
        'plafon_dilampaui' => 'boolean',
        'eksposur_saat_dibuat' => 'decimal:2',
        'tarif_ppn' => 'decimal:4',
        'total_dpp' => 'decimal:2',
        'total_ppn' => 'decimal:2',
        'grand_total' => 'decimal:2',
    ];

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'pelanggan_id');
    }

    public function gudang(): BelongsTo
    {
        return $this->belongsTo(Gudang::class, 'gudang_id');
    }

    public function sales(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_user_id');
    }

    public function penembusPlafon(): BelongsTo
    {
        return $this->belongsTo(User::class, 'plafon_disetujui_oleh');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PesananPenjualanDetail::class, 'pesanan_penjualan_id');
    }

    public function suratJalan(): HasMany
    {
        return $this->hasMany(SuratJalan::class, 'pesanan_penjualan_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
