<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KodeObjekPajak extends Model
{
    protected $table = 'wms_kode_objek_pajak';

    protected $fillable = ['kode', 'jenis_pph', 'deskripsi', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public const DAFTAR = [
        ['kode' => '24-104-29', 'jenis_pph' => 'PPH23', 'deskripsi' => 'Jasa perawatan/perbaikan/pemeliharaan mesin, peralatan, listrik, telepon, air, gas, AC dan/atau TV kabel (selain oleh pengusaha konstruksi berizin)'],
        ['kode' => '24-100-02', 'jenis_pph' => 'PPH23', 'deskripsi' => 'Sewa dan penghasilan lain sehubungan dengan penggunaan harta, kecuali sewa tanah dan/atau bangunan yang dikenai PPh Pasal 4 ayat (2)'],
        ['kode' => '28-403-02', 'jenis_pph' => 'PPH4A2', 'deskripsi' => 'Persewaan tanah dan/atau bangunan'],
    ];

    public function scopeAktif($query)
    {
        return $query->where('is_active', true);
    }
}
