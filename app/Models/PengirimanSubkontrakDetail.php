<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PengirimanSubkontrakDetail extends Model
{
    protected $table = 'wms_pengiriman_subkontrak_detail';

    protected $fillable = ['pengiriman_subkontrak_id', 'bahan_id', 'jumlah', 'jumlah_kembali'];

    protected $casts = [
        'jumlah' => 'decimal:6',
        'jumlah_kembali' => 'decimal:6',
    ];

    public function pengiriman(): BelongsTo
    {
        return $this->belongsTo(PengirimanSubkontrak::class, 'pengiriman_subkontrak_id');
    }

    public function bahan(): BelongsTo
    {
        return $this->belongsTo(Bahan::class, 'bahan_id');
    }

    public function sisaDiVendor(): float
    {
        return round((float) $this->jumlah - (float) $this->jumlah_kembali, 6);
    }
}
