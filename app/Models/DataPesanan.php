<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DataPesanan extends Model
{
    use Auditable;

    public const DRAFT = 'DRAFT';
    public const DIRILIS = 'DIRILIS';
    public const SELESAI = 'SELESAI';
    public const DITUTUP = 'DITUTUP';
    public const DIBATALKAN = 'DIBATALKAN';

    protected $table = 'wms_data_pesanan';

    protected $fillable = [
        'nomor', 'tanggal', 'pesanan_penjualan_id', 'pesanan_penjualan_detail_id',
        'bahan_hasil_id', 'gudang_id', 'jumlah_rencana', 'jumlah_selesai', 'jumlah_terkirim',
        'biaya_per_unit', 'status', 'keterangan', 'dibuat_oleh', 'diselesaikan_oleh', 'diselesaikan_pada',
    ];

    protected $casts = [
        'tanggal' => 'date',
        'jumlah_rencana' => 'decimal:6',
        'jumlah_selesai' => 'decimal:6',
        'jumlah_terkirim' => 'decimal:6',
        'biaya_per_unit' => 'decimal:4',
        'diselesaikan_pada' => 'datetime',
    ];

    public function pesananPenjualan(): BelongsTo
    {
        return $this->belongsTo(PesananPenjualan::class, 'pesanan_penjualan_id');
    }

    public function pesananDetail(): BelongsTo
    {
        return $this->belongsTo(PesananPenjualanDetail::class, 'pesanan_penjualan_detail_id');
    }

    public function bahanHasil(): BelongsTo
    {
        return $this->belongsTo(Bahan::class, 'bahan_hasil_id');
    }

    public function gudang(): BelongsTo
    {
        return $this->belongsTo(Gudang::class, 'gudang_id');
    }

    public function biaya(): HasMany
    {
        return $this->hasMany(DataPesananBiaya::class, 'data_pesanan_id');
    }

    public function pemakaian(): HasMany
    {
        return $this->hasMany(PemakaianBarang::class, 'data_pesanan_id');
    }

    public function menerimaBiaya(): bool
    {
        return in_array($this->status, [self::DRAFT, self::DIRILIS], true);
    }

    public function sudahSelesai(): bool
    {
        return in_array($this->status, [self::SELESAI, self::DITUTUP], true);
    }

    public function totalBiaya(): float
    {
        return round((float) $this->biaya()->sum('nilai'), 2);
    }

    public function jumlahBelumTerkirim(): float
    {
        return round((float) $this->jumlah_selesai - (float) $this->jumlah_terkirim, 6);
    }

    public function saldoWip(): float
    {
        if (!$this->sudahSelesai()) {
            return $this->totalBiaya();
        }

        return round($this->totalBiaya() - $this->totalDilepas(), 2);
    }

    public function totalDilepas(): float
    {
        return round((float) SuratJalanDetail::where('data_pesanan_id', $this->id)
            ->whereHas('suratJalan', fn ($query) => $query->whereIn('status', [SuratJalan::DRAFT, SuratJalan::POSTED]))
            ->sum('hpp'), 2);
    }

    public static function saldoWipBatch(iterable $pesanan): float
    {
        return round(array_sum(static::saldoWipPerId($pesanan)), 2);
    }

    public static function saldoWipPerId(iterable $pesanan): array
    {
        $pesanan = collect($pesanan);

        if ($pesanan->isEmpty()) {
            return [];
        }

        $biaya = DataPesananBiaya::whereIn('data_pesanan_id', $pesanan->pluck('id'))
            ->groupBy('data_pesanan_id')
            ->selectRaw('data_pesanan_id, SUM(nilai) as total')
            ->pluck('total', 'data_pesanan_id');

        $dilepas = SuratJalanDetail::query()
            ->join('wms_surat_jalan as sj', 'sj.id', '=', 'wms_surat_jalan_detail.surat_jalan_id')
            ->whereIn('sj.status', [SuratJalan::DRAFT, SuratJalan::POSTED])
            ->whereIn('wms_surat_jalan_detail.data_pesanan_id', $pesanan->pluck('id'))
            ->groupBy('wms_surat_jalan_detail.data_pesanan_id')
            ->selectRaw('wms_surat_jalan_detail.data_pesanan_id as data_pesanan_id, SUM(wms_surat_jalan_detail.hpp) as total')
            ->pluck('total', 'data_pesanan_id');

        return $pesanan->mapWithKeys(function (DataPesanan $item) use ($biaya, $dilepas) {
            $total = round((float) ($biaya[$item->id] ?? 0), 2);

            return [$item->id => $item->sudahSelesai()
                ? round($total - round((float) ($dilepas[$item->id] ?? 0), 2), 2)
                : $total];
        })->all();
    }

    public function scopeBerjalan($query)
    {
        return $query->whereIn('status', [self::DRAFT, self::DIRILIS, self::SELESAI]);
    }
}
