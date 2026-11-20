<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LampiranDokumen extends Model
{
    protected $table = 'wms_lampiran_dokumen';
    protected $guarded = ['id'];

    protected $casts = [
        'ukuran' => 'integer',
    ];

    public const KATEGORI = [
        'FAKTUR' => 'Scan Faktur / Invoice Supplier',
        'FAKTUR_PAJAK' => 'Faktur Pajak',
        'PO' => 'PO Bertanda Tangan',
        'SURAT_JALAN' => 'Surat Jalan Supplier',
        'QC' => 'Foto / Berita Acara QC',
        'ASET' => 'Dokumen & Foto Aset',
        'BUKTI_POTONG' => 'Bukti Potong dari Coretax (PDF)',
        'BUKTI_BAYAR' => 'Bukti Transfer / Pembayaran',
        'LAIN' => 'Lain-lain',
    ];

    public function lampiran(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function getLabelKategoriAttribute(): string
    {
        return self::KATEGORI[$this->kategori] ?? $this->kategori;
    }

    public function getUkuranTerbacaAttribute(): string
    {
        $bytes = (int) $this->ukuran;

        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return round($bytes / 1048576, 2) . ' MB';
    }

    public function getIkonAttribute(): string
    {
        return match (true) {
            str_contains($this->mime, 'pdf') => 'fa-file-pdf text-error',
            str_starts_with($this->mime, 'image/') => 'fa-file-image text-info',
            default => 'fa-file text-base-content/60',
        };
    }
}
