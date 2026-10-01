<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenerimaanPembayaran extends Model
{
    use Auditable;

    public const POSTED = 'POSTED';
    public const VOID = 'VOID';

    protected $table = 'wms_penerimaan_pembayaran';

    protected $fillable = [
        'nomor', 'tanggal', 'faktur_penjualan_id', 'pelanggan_id', 'coa_kas_bank_id',
        'jumlah', 'jumlah_uang_muka', 'uang_muka_id', 'referensi', 'status', 'journal_id', 'dibuat_oleh',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'jumlah' => 'decimal:2',
    ];

    public function uangMuka(): BelongsTo
    {
        return $this->belongsTo(UangMukaPelanggan::class, 'uang_muka_id');
    }

    public function faktur(): BelongsTo
    {
        return $this->belongsTo(FakturPenjualan::class, 'faktur_penjualan_id');
    }

    public function pelanggan(): BelongsTo
    {
        return $this->belongsTo(Pelanggan::class, 'pelanggan_id');
    }

    public function kasBank(): BelongsTo
    {
        return $this->belongsTo(BaganAkun::class, 'coa_kas_bank_id');
    }
}
