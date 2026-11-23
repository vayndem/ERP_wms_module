<?php

namespace App\Http\Requests;

use App\Models\FakturPembelian;
use App\Rules\NsfpValid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFakturPembelianRequest extends FormRequest
{
    public function authorize(): bool
    {
        $id = $this->route('faktur_pembelian') ?? $this->route('id');
        $invoice = FakturPembelian::find($id);

        return $invoice ? ($this->user()?->can('update', $invoice) ?? false) : false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'kode_supplier' => $this->kode_supplier ?? $this->supplier_id ?? $this->id_suplier,
            'diskon'        => $this->diskon ?? 0,
            'ongkir'        => $this->ongkir ?? 0,
            'ppn_impor'     => $this->ppn_impor ?? 0,
            'pph'           => $this->pph ?? 0,
            'is_ppn'        => $this->boolean('is_ppn'),
        ]);

        if (!$this->boolean('is_ppn')) {
            $this->merge([
                'no_faktur_pajak'      => null,
                'tanggal_faktur_pajak' => null,
            ]);
        }
    }

    public function rules(): array
    {
        $idTarget = $this->route('faktur_pembelian') ?? $this->route('id');

        return [
            'no_invoice'              => 'required|string|max:100|unique:wms_faktur_pembelian,no_invoice,' . $idTarget,
            'kode_supplier'           => 'required|exists:suppliers,id',
            'lpb_ids'                 => 'required|array|min:1',
            'lpb_ids.*'               => 'required|integer|distinct|exists:wms_penerimaan_barang,id',
            'tanggal'                 => 'required|date',
            'tgl_deadline_pembayaran' => 'nullable|date',
            'is_ppn'                  => 'required|boolean',
            'no_faktur_pajak'         => [
                Rule::requiredIf(fn () => $this->boolean('is_ppn')),
                'nullable', 'string', 'max:30',
                new NsfpValid(),
            ],
            'tanggal_faktur_pajak'    => ['nullable', 'date'],
            'ppnbm'                   => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'kode_objek_pajak'        => [
                'nullable', 'string',
                Rule::exists('wms_kode_objek_pajak', 'kode')
                    ->where('is_active', true)
                    ->where('jenis_pph', $this->input('jenis_pph')),
            ],
            'diskon'                  => 'nullable|numeric|min:0',
            'ongkir'                  => 'nullable|numeric|min:0',
            'ppn_impor'               => 'nullable|numeric|min:0',
            'jenis_pph'               => ['nullable', 'string', Rule::in(['PPH23', 'PPH22', 'PPH4A2'])],
            'note'                    => 'nullable|string',
            'mata_uang_asing'         => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'kurs'                    => ['nullable', 'numeric', 'min:0.0001', Rule::requiredIf(fn () => filled($this->mata_uang_asing))],
            'nilai_asing'             => ['nullable', 'numeric', 'min:0.01', Rule::requiredIf(fn () => filled($this->mata_uang_asing))],
        ];
    }

    public function messages(): array
    {
        return [
            'kode_objek_pajak.exists' => 'Kode objek pajak tidak dikenal atau tidak sesuai dengan jenis PPh yang dipilih.',
        ];
    }
}
