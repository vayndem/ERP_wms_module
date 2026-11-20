<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasLampiran;
use Illuminate\Database\Eloquent\Model;

class BuktiPotong extends Model
{
    use Auditable, HasLampiran;

    public const DRAFT = 'DRAFT';
    public const DITERBITKAN = 'DITERBITKAN';
    public const DIBATALKAN = 'DIBATALKAN';
    public const DIGANTI = 'DIGANTI';

    public const JENIS_DIDUKUNG = ['PPH23', 'PPH4A2'];

    public const LABEL_JENIS = [
        'PPH23' => 'PPh 23',
        'PPH4A2' => 'PPh 4(2)',
        'PPH22' => 'PPh 22',
    ];

    protected $table = 'wms_bukti_potong';

    protected $fillable = [
        'pembayaran_id',
        'urutan',
        'versi',
        'supplier_id',
        'jenis_pph',
        'kode_objek_pajak',
        'masa_pajak',
        'tahun_pajak',
        'tanggal_pemotongan',
        'dasar_pengenaan',
        'tarif',
        'jumlah_pph',
        'penerima_nama',
        'penerima_npwp',
        'penerima_nik',
        'penerima_nitku',
        'penerima_alamat',
        'status',
        'perlu_pembatalan',
        'catatan',
        'nomor_bukti_djp',
        'tanggal_diterbitkan',
        'menggantikan_id',
        'dibuat_oleh',
        'diterbitkan_oleh',
        'diterbitkan_pada',
        'dibatalkan_oleh',
        'dibatalkan_pada',
    ];

    protected $casts = [
        'tanggal_pemotongan' => 'date',
        'tanggal_diterbitkan' => 'date',
        'dasar_pengenaan' => 'decimal:2',
        'tarif' => 'decimal:4',
        'jumlah_pph' => 'decimal:2',
        'perlu_pembatalan' => 'boolean',
        'diterbitkan_pada' => 'datetime',
        'dibatalkan_pada' => 'datetime',
    ];

    public function pembayaran()
    {
        return $this->belongsTo(PembayaranFaktur::class, 'pembayaran_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'supplier_id')->withTrashed();
    }

    public function kodeObjek()
    {
        return $this->belongsTo(KodeObjekPajak::class, 'kode_objek_pajak', 'kode');
    }

    public function menggantikan()
    {
        return $this->belongsTo(self::class, 'menggantikan_id');
    }

    public function penggantinya()
    {
        return $this->hasOne(self::class, 'menggantikan_id');
    }

    public function pembuat()
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    public function penerbit()
    {
        return $this->belongsTo(User::class, 'diterbitkan_oleh');
    }

    public function scopeAktif($query)
    {
        return $query->whereIn($query->getModel()->getTable() . '.status', [self::DRAFT, self::DITERBITKAN]);
    }

    public function getLabelJenisAttribute(): string
    {
        return self::LABEL_JENIS[$this->jenis_pph] ?? $this->jenis_pph;
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function isDiterbitkan(): bool
    {
        return $this->status === self::DITERBITKAN;
    }
}
