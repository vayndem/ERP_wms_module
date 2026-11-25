<?php

namespace App\Models;

use App\Models\Concerns\HasLampiran;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PembayaranFaktur extends Model
{
    use HasFactory, HasLampiran;

    public const POSTED = 'POSTED';
    public const VOID = 'VOID';

    protected $table = 'wms_pembayaran_faktur';

    protected $fillable = [
        'payment_number',
        'invoice_lpb_id',
        'supplier_id',
        'tanggal_pembayaran',
        'metode_pembayaran',
        'coa_kas_bank_id',
        'jumlah_pembayaran',
        'potongan_pph',
        'potongan_materai',
        'biaya_transfer_bank',
        'selisih_bayar',
        'jenis_selisih',
        'coa_selisih_id',
        'kelebihan_pembayaran',
        'uang_muka_sumber_payment_id',
        'uang_muka_dipakai',
        'total_transaksi_pengurang_hutang',
        'keterangan',
        'finance_user_id',
        'status',
        'voided_by',
        'voided_at',
        'void_reason',
    ];

    protected $casts = [
        'tanggal_pembayaran' => 'date',
        'jumlah_pembayaran' => 'decimal:2',
        'potongan_pph' => 'decimal:2',
        'potongan_materai' => 'decimal:2',
        'biaya_transfer_bank' => 'decimal:2',
        'selisih_bayar' => 'decimal:2',
        'kelebihan_pembayaran' => 'decimal:2',
        'uang_muka_dipakai' => 'decimal:2',
        'total_transaksi_pengurang_hutang' => 'decimal:2',
        'voided_at' => 'datetime',
    ];

    public function invoice()
    {
        return $this->belongsTo(FakturPembelian::class, 'invoice_lpb_id');
    }

    public function userFinance()
    {
        return $this->belongsTo(User::class, 'finance_user_id');
    }

    public function coaKasBank()
    {
        return $this->belongsTo(BaganAkun::class, 'coa_kas_bank_id');
    }

    public function coaSelisih()
    {
        return $this->belongsTo(BaganAkun::class, 'coa_selisih_id');
    }

    public function sumberUangMuka()
    {
        return $this->belongsTo(self::class, 'uang_muka_sumber_payment_id');
    }

    public function pemakaianUangMuka()
    {
        return $this->hasMany(self::class, 'uang_muka_sumber_payment_id')->where('status', self::POSTED);
    }

    public function sisaUangMuka(): float
    {
        if ($this->jenis_selisih !== 'UANG_MUKA_SUPPLIER') {
            return 0.0;
        }

        return round((float) $this->kelebihan_pembayaran - (float) $this->pemakaianUangMuka()->sum('uang_muka_dipakai'), 2);
    }
}
