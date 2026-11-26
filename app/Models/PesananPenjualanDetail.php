<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PesananPenjualanDetail extends Model
{
    protected $table = 'wms_pesanan_penjualan_detail';

    protected $fillable = [
        'pesanan_penjualan_id', 'bahan_id', 'jumlah', 'jumlah_terkirim',
        'harga_satuan', 'total_harga', 'satuan',
    ];

    protected $casts = [
        'jumlah' => 'decimal:6',
        'jumlah_terkirim' => 'decimal:6',
        'harga_satuan' => 'decimal:2',
        'total_harga' => 'decimal:2',
    ];

    public function pesanan(): BelongsTo
    {
        return $this->belongsTo(PesananPenjualan::class, 'pesanan_penjualan_id');
    }

    public function bahan(): BelongsTo
    {
        return $this->belongsTo(Bahan::class, 'bahan_id');
    }

    public function suratJalanDetails()
    {
        return $this->hasMany(SuratJalanDetail::class, 'pesanan_penjualan_detail_id');
    }

    public function jumlahTerikat(): float
    {
        return round((float) $this->suratJalanDetails()
            ->whereHas('suratJalan', fn ($query) => $query->whereIn('status', [SuratJalan::DRAFT, SuratJalan::POSTED]))
            ->sum('jumlah'), 6);
    }

    public function sisaKirim(): float
    {
        return round((float) $this->jumlah - $this->jumlahTerikat(), 6);
    }
}
