<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AccountingSetting extends Model
{
    public const HUTANG_USAHA = 'HUTANG_USAHA';
    public const PPN_MASUKAN = 'PPN_MASUKAN';
    public const PPN_IMPOR = 'PPN_IMPOR';
    public const PPN_KELUARAN = 'PPN_KELUARAN';
    public const HUTANG_PPH23 = 'HUTANG_PPH23';
    public const HUTANG_PPH22 = 'HUTANG_PPH22';
    public const HUTANG_PPH4A2 = 'HUTANG_PPH4A2';
    public const BIAYA_BANK = 'BIAYA_BANK';
    public const BEBAN_MATERAI = 'BEBAN_MATERAI';
    public const SELISIH_BAYAR = 'SELISIH_BAYAR';
    public const BIAYA_ONGKIR = 'BIAYA_ONGKIR';
    public const DISKON_PEMBELIAN = 'DISKON_PEMBELIAN';
    public const UANG_MUKA_SUPPLIER = 'UANG_MUKA_SUPPLIER';
    public const HUTANG_PPH_BADAN = 'HUTANG_PPH_BADAN';
    public const BEBAN_PPH_BADAN = 'BEBAN_PPH_BADAN';
    public const ASET_PAJAK_TANGGUHAN = 'ASET_PAJAK_TANGGUHAN';
    public const LIABILITAS_PAJAK_TANGGUHAN = 'LIABILITAS_PAJAK_TANGGUHAN';
    public const BEBAN_PAJAK_TANGGUHAN = 'BEBAN_PAJAK_TANGGUHAN';
    public const PIUTANG_USAHA = 'PIUTANG_USAHA';
    public const PENJUALAN = 'PENJUALAN';
    public const RETUR_PENJUALAN = 'RETUR_PENJUALAN';
    public const BEBAN_POKOK_PENJUALAN = 'BEBAN_POKOK_PENJUALAN';
    public const BARANG_DALAM_PROSES = 'BARANG_DALAM_PROSES';
    public const PERSEDIAAN_DALAM_PERJALANAN = 'PERSEDIAAN_DALAM_PERJALANAN';
    public const BIAYA_MASIH_HARUS_DIBAYAR = 'BIAYA_MASIH_HARUS_DIBAYAR';
    public const UANG_MUKA_PELANGGAN = 'UANG_MUKA_PELANGGAN';
    public const BEBAN_TENAGA_KERJA_DISERAP = 'BEBAN_TENAGA_KERJA_DISERAP';
    public const BEBAN_OVERHEAD_DISERAP = 'BEBAN_OVERHEAD_DISERAP';
    public const RUGI_PEMBATALAN_PRODUKSI = 'RUGI_PEMBATALAN_PRODUKSI';
    public const LABA_SELISIH_KURS = 'LABA_SELISIH_KURS';
    public const RUGI_SELISIH_KURS = 'RUGI_SELISIH_KURS';

    public const PPH_LIABILITY_KEY = [
        'PPH23' => self::HUTANG_PPH23,
        'PPH22' => self::HUTANG_PPH22,
        'PPH4A2' => self::HUTANG_PPH4A2,
    ];

    protected $fillable = ['key', 'coa_id', 'description'];

    public function coa(): BelongsTo
    {
        return $this->belongsTo(BaganAkun::class, 'coa_id');
    }

    public static function accountId(string $key): int
    {
        $setting = static::with('coa')->where('key', $key)->first();
        $expected = [
            self::HUTANG_USAHA => [['LIABILITAS', 'KREDIT']],
            self::PPN_MASUKAN => [['ASET', 'DEBIT']],
            self::PPN_IMPOR => [['ASET', 'DEBIT']],
            self::PPN_KELUARAN => [['LIABILITAS', 'KREDIT']],
            self::HUTANG_PPH23 => [['LIABILITAS', 'KREDIT']],
            self::HUTANG_PPH22 => [['LIABILITAS', 'KREDIT']],
            self::HUTANG_PPH4A2 => [['LIABILITAS', 'KREDIT']],
            self::BIAYA_BANK => [['BEBAN', 'DEBIT']],
            self::BEBAN_MATERAI => [['BEBAN', 'DEBIT']],
            self::SELISIH_BAYAR => [['PENDAPATAN', 'KREDIT']],
            self::BIAYA_ONGKIR => [['BEBAN', 'DEBIT']],
            self::DISKON_PEMBELIAN => [['BEBAN', 'KREDIT']],
            self::UANG_MUKA_SUPPLIER => [['ASET', 'DEBIT']],
            self::HUTANG_PPH_BADAN => [['LIABILITAS', 'KREDIT']],
            self::BEBAN_PPH_BADAN => [['BEBAN', 'DEBIT']],
            self::ASET_PAJAK_TANGGUHAN => [['ASET', 'DEBIT']],
            self::LIABILITAS_PAJAK_TANGGUHAN => [['LIABILITAS', 'KREDIT']],
            self::BEBAN_PAJAK_TANGGUHAN => [['BEBAN', 'DEBIT']],
            self::PIUTANG_USAHA => [['ASET', 'DEBIT']],
            self::PENJUALAN => [['PENDAPATAN', 'KREDIT']],
            self::RETUR_PENJUALAN => [['PENDAPATAN', 'DEBIT']],
            self::BEBAN_POKOK_PENJUALAN => [['BEBAN', 'DEBIT']],
            self::BARANG_DALAM_PROSES => [['ASET', 'DEBIT']],
            self::PERSEDIAAN_DALAM_PERJALANAN => [['ASET', 'DEBIT']],
            self::BIAYA_MASIH_HARUS_DIBAYAR => [['LIABILITAS', 'KREDIT']],
            self::UANG_MUKA_PELANGGAN => [['LIABILITAS', 'KREDIT']],
            self::BEBAN_TENAGA_KERJA_DISERAP => [['BEBAN', 'KREDIT']],
            self::BEBAN_OVERHEAD_DISERAP => [['BEBAN', 'KREDIT']],
            self::RUGI_PEMBATALAN_PRODUKSI => [['BEBAN', 'DEBIT']],
            self::LABA_SELISIH_KURS => [['PENDAPATAN', 'KREDIT']],
            self::RUGI_SELISIH_KURS => [['BEBAN', 'DEBIT']],
        ];
        if (
            !$setting
            || !$setting->coa
            || !isset($expected[$key])
            || !$setting->coa->isUsableFor($expected[$key])
        ) {
            throw new RuntimeException("Mapping akun {$key} belum diatur atau akun tidak aktif.");
        }

        return (int) $setting->coa_id;
    }
}
