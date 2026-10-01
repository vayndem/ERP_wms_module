<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JamKerjaProduksi extends Model
{
    protected $table = 'wms_jam_kerja_produksi';

    protected $fillable = [
        'nomor', 'tanggal', 'data_pesanan_id', 'pusat_kerja_id', 'jam',
        'tarif_tenaga_kerja_per_jam', 'tarif_overhead_per_jam',
        'biaya_tenaga_kerja', 'biaya_overhead', 'keterangan', 'journal_id', 'dibuat_oleh',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'jam' => 'decimal:2',
        'tarif_tenaga_kerja_per_jam' => 'decimal:2',
        'tarif_overhead_per_jam' => 'decimal:2',
        'biaya_tenaga_kerja' => 'decimal:2',
        'biaya_overhead' => 'decimal:2',
    ];

    public function pesanan(): BelongsTo
    {
        return $this->belongsTo(DataPesanan::class, 'data_pesanan_id');
    }

    public function pusatKerja(): BelongsTo
    {
        return $this->belongsTo(PusatKerja::class, 'pusat_kerja_id');
    }

    public function petugas(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function totalBiaya(): float
    {
        return round((float) $this->biaya_tenaga_kerja + (float) $this->biaya_overhead, 2);
    }
}
