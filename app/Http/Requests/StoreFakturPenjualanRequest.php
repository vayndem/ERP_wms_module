<?php

namespace App\Http\Requests;

use App\Rules\NsfpValid;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFakturPenjualanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('faktur', $this->route('suratJalan')) ?? false;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'jatuh_tempo' => ['nullable', 'date', 'after_or_equal:tanggal'],
            'no_faktur_pajak' => [
                'nullable', 'string', 'max:30', new NsfpValid(),
                Rule::unique('wms_faktur_penjualan', 'no_faktur_pajak'),
            ],
            'tanggal_faktur_pajak' => ['nullable', 'date'],
            'ppnbm' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
