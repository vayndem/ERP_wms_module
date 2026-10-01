<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UangMukaPelanggan extends Model
{
    public const AKTIF = 'AKTIF';
    public const TERPAKAI = 'TERPAKAI';

    protected $table = 'wms_uang_muka_pelanggan';

    protected $fillable = [
        'nomor', 'tanggal', 'pelanggan_id', 'penerimaan_pembayaran_id',
        'jumlah', 'sisa', 'status', 'keterangan', 'dibuat_oleh',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'jumlah' => 'decimal:2',
        'sisa' => 'decimal:2',
    ];

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'pelanggan_id');
    }

    public function penerimaan(): BelongsTo
    {
        return $this->belongsTo(PenerimaanPembayaran::class, 'penerimaan_pembayaran_id');
    }

    public function pemakaian(): HasMany
    {
        return $this->hasMany(PenerimaanPembayaran::class, 'uang_muka_id');
    }

    public function isAktif(): bool
    {
        return $this->status === self::AKTIF && (float) $this->sisa > 0.005;
    }

    public static function saldoPelanggan(int $pelangganId): float
    {
        return round((float) static::where('pelanggan_id', $pelangganId)
            ->where('status', self::AKTIF)
            ->sum('sisa'), 2);
    }
}
