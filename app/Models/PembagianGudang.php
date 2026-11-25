<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PembagianGudang extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['boleh_menerima' => 'boolean', 'boleh_npk' => 'boolean', 'boleh_transfer' => 'boolean', 'boleh_opname' => 'boolean'];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function gudang()
    {
        return $this->belongsTo(Gudang::class);
    }
}
