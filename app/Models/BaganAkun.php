<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class BaganAkun extends Model
{
    use HasFactory;

    public const FISKAL_NONE = 'NONE';
    public const FISKAL_BEDA_TETAP = 'BEDA_TETAP';
    public const FISKAL_BEDA_WAKTU = 'BEDA_WAKTU';
    public const FISKAL_PENGHASILAN_FINAL = 'PENGHASILAN_FINAL';

    public const KLASIFIKASI_FISKAL = [
        self::FISKAL_NONE => 'Tidak dikoreksi',
        self::FISKAL_BEDA_TETAP => 'Beda Tetap',
        self::FISKAL_BEDA_WAKTU => 'Beda Waktu',
        self::FISKAL_PENGHASILAN_FINAL => 'Penghasilan Kena PPh Final',
    ];

    protected $table = 'wms_bagan_akun';

    protected $fillable = [
        'kode_akun',
        'nama_akun',
        'kategori_akun',
        'posisi_normal',
        'is_active',
        'is_postable',
        'is_cash_bank',
        'klasifikasi_fiskal',
        'keterangan',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_postable' => 'boolean',
        'is_cash_bank' => 'boolean',
    ];

    public function jurnalDetails()
    {
        return $this->hasMany(JurnalDetail::class, 'coa_id');
    }

    public function isMapped(): bool
    {
        return AccountingSetting::where('coa_id', $this->id)->exists()
            || KategoriBahan::where('coa_persediaan_id', $this->id)
            ->orWhere('coa_beban_id', $this->id)
            ->orWhere('coa_clearing_lpb_id', $this->id)
            ->orWhere('coa_beban_selisih_opname_id', $this->id)
            ->orWhere('coa_koreksi_opname_id', $this->id)
            ->exists()
            || KategoriAset::where('akun_aset_id', $this->id)
                ->orWhere('accumulated_depreciation_coa_id', $this->id)
                ->orWhere('depreciation_expense_coa_id', $this->id)
                ->orWhere('disposal_gain_coa_id', $this->id)
                ->orWhere('disposal_loss_coa_id', $this->id)
                ->exists()
            || KategoriJasa::where('expense_coa_id', $this->id)
                ->orWhere('grni_coa_id', $this->id)
                ->exists()
            || Aset::where('acquisition_credit_coa_id', $this->id)->exists()
            || PelepasanAset::where('cash_bank_coa_id', $this->id)->exists();
    }

    public function isUsableFor(array $allowedPairs, ?bool $mustBeCashBank = null): bool
    {
        $pairMatches = collect($allowedPairs)->contains(
            fn (array $pair) => $this->kategori_akun === $pair[0] && $this->posisi_normal === $pair[1]
        );

        return $this->is_active
            && $this->is_postable
            && $pairMatches
            && ($mustBeCashBank === null || $this->is_cash_bank === $mustBeCashBank);
    }

    public static function assertUsable(
        ?int $accountId,
        array $allowedPairs,
        string $role,
        ?bool $mustBeCashBank = null
    ): self {
        $account = $accountId ? static::find($accountId) : null;
        if (!$account || !$account->isUsableFor($allowedPairs, $mustBeCashBank)) {
            throw new RuntimeException("Mapping COA {$role} tidak aktif, tidak postable, atau tipe/posisi normalnya tidak sesuai.");
        }

        return $account;
    }
}
