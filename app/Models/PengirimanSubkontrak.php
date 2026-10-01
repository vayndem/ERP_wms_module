<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PengirimanSubkontrak extends Model
{
    public const DIKIRIM = 'DIKIRIM';
    public const SEBAGIAN_KEMBALI = 'SEBAGIAN_KEMBALI';
    public const SELESAI = 'SELESAI';

    protected $table = 'wms_pengiriman_subkontrak';

    protected $fillable = [
        'nomor', 'tanggal', 'supplier_id', 'gudang_asal_id', 'gudang_subkontrak_id',
        'estimasi_kembali', 'status', 'keperluan', 'keterangan', 'transfer_keluar_id', 'dibuat_oleh',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'estimasi_kembali' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function gudangAsal(): BelongsTo
    {
        return $this->belongsTo(Gudang::class, 'gudang_asal_id');
    }

    public function gudangSubkontrak(): BelongsTo
    {
        return $this->belongsTo(Gudang::class, 'gudang_subkontrak_id');
    }

    public function details(): HasMany
    {
        return $this->hasMany(PengirimanSubkontrakDetail::class, 'pengiriman_subkontrak_id');
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function masihDiVendor(): bool
    {
        return in_array($this->status, [self::DIKIRIM, self::SEBAGIAN_KEMBALI], true);
    }

    public function terlambat(): bool
    {
        return $this->masihDiVendor()
            && $this->estimasi_kembali !== null
            && $this->estimasi_kembali->lt(today());
    }
}
