<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pelanggan extends Model
{
    use HasFactory, SoftDeletes, Auditable;

    protected $table = 'pelanggans';

    protected $fillable = [
        'kode', 'nama', 'npwp', 'nik', 'nitku', 'tipe_identitas', 'status_pkp', 'telp', 'email', 'alamat', 'up',
        'termin_hari', 'plafon_kredit', 'is_active',
    ];

    protected $casts = [
        'plafon_kredit' => 'decimal:2',
        'is_active' => 'boolean',
        'status_pkp' => 'boolean',
    ];

    public function pesanan()
    {
        return $this->hasMany(PesananPenjualan::class, 'pelanggan_id');
    }

    public function faktur()
    {
        return $this->hasMany(FakturPenjualan::class, 'pelanggan_id');
    }

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }

    public function piutangBerjalan(): float
    {
        return round((float) $this->faktur()
            ->whereIn('status', [FakturPenjualan::POSTED, FakturPenjualan::PARTIALLY_PAID])
            ->sum('sisa_tagihan'), 2);
    }
}
