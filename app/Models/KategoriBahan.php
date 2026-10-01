<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KategoriBahan extends Model
{
    use HasFactory;

    protected $table = 'kategori_bahans';

    protected $fillable = [
        'katnama',
        'tipe_pembebanan_id',
        'coa_persediaan_id',
        'coa_beban_id',
        'coa_clearing_lpb_id',
        'coa_beban_selisih_opname_id',
        'coa_koreksi_opname_id',
    ];

    public function bahan(): HasMany
    {
        return $this->hasMany(Bahan::class, 'kategori', 'id');
    }

    public function bahanByType(): HasMany
    {
        return $this->hasMany(Bahan::class, 'tipe_barang', 'id');
    }

    public function lpbDetails(): HasMany
    {
        return $this->hasMany(PenerimaanBarangDetail::class, 'id_kategori', 'id');
    }

    public function serviceCategories(): HasMany
    {
        return $this->hasMany(KategoriJasa::class, 'kategori_bahan_id', 'id');
    }

    public function servicePoDetails(): HasMany
    {
        return $this->hasMany(PesananJasaDetail::class, 'id_kategori', 'id');
    }

    public function serviceBapDetails(): HasMany
    {
        return $this->hasMany(PenerimaanJasaDetail::class, 'id_kategori', 'id');
    }

    public function tipePembebanan(): BelongsTo
    {
        return $this->belongsTo(TipePembebanan::class, 'tipe_pembebanan_id');
    }

    public function coaPersediaan(): BelongsTo
    {
        return $this->belongsTo(BaganAkun::class, 'coa_persediaan_id');
    }

    public function coaBeban(): BelongsTo
    {
        return $this->belongsTo(BaganAkun::class, 'coa_beban_id');
    }

    public function coaClearingLpb(): BelongsTo
    {
        return $this->belongsTo(BaganAkun::class, 'coa_clearing_lpb_id');
    }

    public function kategoriJasa(): HasMany
    {
        return $this->hasMany(KategoriJasa::class, 'kategori_bahan_id');
    }

    public function isKategoriJasa(): bool
    {
        return $this->kategoriJasa()->exists();
    }

    public static function idKategoriJasa(): array
    {
        return KategoriJasa::whereNotNull('kategori_bahan_id')
            ->distinct()
            ->pluck('kategori_bahan_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function coaBebanSelisihOpname(): BelongsTo
    {
        return $this->belongsTo(BaganAkun::class, 'coa_beban_selisih_opname_id');
    }

    public function coaKoreksiOpname(): BelongsTo
    {
        return $this->belongsTo(BaganAkun::class, 'coa_koreksi_opname_id');
    }
}
