<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\BaganAkun;
use App\Models\KategoriBahan;

class UpdateAccountingMappingRequest extends FormRequest
{
    private const ATURAN_KATEGORI_BARANG = [
        'coa_persediaan_id' => [['ASET', 'DEBIT']],
        'coa_beban_id' => [['BEBAN', 'DEBIT']],
        'coa_clearing_lpb_id' => [['LIABILITAS', 'KREDIT']],
        'coa_beban_selisih_opname_id' => [['BEBAN', 'DEBIT']],
        'coa_koreksi_opname_id' => [['PENDAPATAN', 'KREDIT']],
    ];

    private const ATURAN_KATEGORI_JASA = [
        'coa_persediaan_id' => [['ASET', 'DEBIT'], ['BEBAN', 'DEBIT']],
        'coa_beban_id' => [['BEBAN', 'DEBIT'], ['ASET', 'DEBIT']],
        'coa_clearing_lpb_id' => [['LIABILITAS', 'KREDIT']],
        'coa_beban_selisih_opname_id' => [['BEBAN', 'DEBIT']],
        'coa_koreksi_opname_id' => [['PENDAPATAN', 'KREDIT']],
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('updateMapping', BaganAkun::class) ?? false;
    }

    public function rules(): array
    {
        $account = fn() => ['required', 'integer', Rule::exists('wms_bagan_akun', 'id')
            ->where(fn($query) => $query->where('is_active', 1)->where('is_postable', 1))];

        return [
            'global' => 'required|array',
            'global.HUTANG_USAHA' => $account(),
            'global.PPN_MASUKAN' => $account(),
            'global.PPN_IMPOR' => $account(),
            'global.HUTANG_PPH23' => $account(),
            'global.HUTANG_PPH22' => $account(),
            'global.HUTANG_PPH4A2' => $account(),
            'global.BIAYA_BANK' => $account(),
            'global.BEBAN_MATERAI' => $account(),
            'global.SELISIH_BAYAR' => $account(),
            'global.BIAYA_ONGKIR' => $account(),
            'global.DISKON_PEMBELIAN' => $account(),
            'global.UANG_MUKA_SUPPLIER' => $account(),
            'categories' => 'required|array',
            'categories.*.coa_persediaan_id' => $account(),
            'categories.*.coa_beban_id' => $account(),
            'categories.*.coa_clearing_lpb_id' => $account(),
            'categories.*.coa_beban_selisih_opname_id' => $account(),
            'categories.*.coa_koreksi_opname_id' => $account(),
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            $expected = [
                'HUTANG_USAHA' => ['LIABILITAS', 'KREDIT'],
                'PPN_MASUKAN' => ['ASET', 'DEBIT'],
                'PPN_IMPOR' => ['ASET', 'DEBIT'],
                'HUTANG_PPH23' => ['LIABILITAS', 'KREDIT'],
                'HUTANG_PPH22' => ['LIABILITAS', 'KREDIT'],
                'HUTANG_PPH4A2' => ['LIABILITAS', 'KREDIT'],
                'BIAYA_BANK' => ['BEBAN', 'DEBIT'],
                'BEBAN_MATERAI' => ['BEBAN', 'DEBIT'],
                'SELISIH_BAYAR' => ['PENDAPATAN', 'KREDIT'],
                'BIAYA_ONGKIR' => ['BEBAN', 'DEBIT'],
                'DISKON_PEMBELIAN' => ['BEBAN', 'KREDIT'],
                'UANG_MUKA_SUPPLIER' => ['ASET', 'DEBIT'],
            ];
            foreach ($this->input('global', []) as $key => $id) {
                $coa = BaganAkun::find($id);
                if (
                    $coa && isset($expected[$key]) &&
                    ($coa->kategori_akun !== $expected[$key][0] || $coa->posisi_normal !== $expected[$key][1])
                ) {
                    $validator->errors()->add("global.{$key}", "Kategori/posisi normal akun {$key} tidak sesuai.");
                }
            }
            $kategoriJasa = KategoriBahan::idKategoriJasa();

            foreach ($this->input('categories', []) as $id => $mapping) {
                $aturan = in_array((int) $id, $kategoriJasa, true)
                    ? self::ATURAN_KATEGORI_JASA
                    : self::ATURAN_KATEGORI_BARANG;

                foreach ($aturan as $field => $pasangan) {
                    $coa = BaganAkun::find($mapping[$field] ?? null);

                    if (!$coa || $coa->isUsableFor($pasangan)) {
                        continue;
                    }

                    $validator->errors()->add(
                        "categories.{$id}.{$field}",
                        in_array((int) $id, $kategoriJasa, true)
                            ? 'Akun tidak sesuai perannya pada kategori jasa.'
                            : 'Kategori atau posisi normal akun tidak sesuai dengan perannya.'
                    );
                }
            }
        }];
    }
}
