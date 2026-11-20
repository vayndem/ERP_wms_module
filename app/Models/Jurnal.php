<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Jurnal extends Model
{
    use HasFactory;

    protected $table = 'wms_jurnal';

    protected $fillable = [
        'no_jurnal',
        'tanggal',
        'keterangan',
        'sumber_transaksi',
        'reff_id',
        'status',
        'created_by',
        'posted_by',
        'posted_at',
        'reversal_of_id',
        'total_debit',
        'total_kredit',
    ];

    protected $casts = ['tanggal' => 'date', 'posted_at' => 'datetime'];

    public const STATUS_BERLAKU = ['POSTED', 'REVERSED'];

    public function scopeBerlaku($query)
    {
        return $query->whereIn($query->getModel()->getTable() . '.status', self::STATUS_BERLAKU);
    }

    public function isManual(): bool
    {
        return $this->sumber_transaksi === 'MANUAL';
    }

    public function isDraft(): bool
    {
        return $this->status === 'DRAFT';
    }

    public function isMenungguPersetujuan(): bool
    {
        return $this->status === 'PENDING_APPROVAL';
    }

    public function details(): HasMany
    {
        return $this->hasMany(JurnalDetail::class, 'jurnal_id');
    }
}
