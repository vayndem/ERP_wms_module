<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PusatKerja extends Model
{
    use Auditable;

    public const AKTIF = 'AKTIF';
    public const NONAKTIF = 'NONAKTIF';

    protected $table = 'wms_pusat_kerja';

    protected $fillable = [
        'kode', 'nama', 'gudang_id', 'kapasitas_menit_per_hari',
        'tarif_tenaga_kerja_per_jam', 'tarif_overhead_per_jam',
        'status', 'keterangan', 'dibuat_oleh',
    ];

    protected $casts = [
        'kapasitas_menit_per_hari' => 'decimal:2',
        'tarif_tenaga_kerja_per_jam' => 'decimal:2',
        'tarif_overhead_per_jam' => 'decimal:2',
    ];

    public function gudang(): BelongsTo
    {
        return $this->belongsTo(Gudang::class, 'gudang_id');
    }

    public function operasi(): HasMany
    {
        return $this->hasMany(OperasiBom::class, 'pusat_kerja_id');
    }

    public function isAktif(): bool
    {
        return $this->status === self::AKTIF;
    }

    public function scopeAktif($query)
    {
        return $query->where('status', self::AKTIF);
    }
}
