<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'suppliers';

    protected $fillable = [
        'nama',
        'alamat',
        'npwp',
        'nik',
        'nitku',
        'tipe_identitas',
        'status_pkp',
        'telp',
        'up',
        'pembayaran',
    ];

    protected $casts = [
        'status_pkp' => 'boolean',
    ];
}
