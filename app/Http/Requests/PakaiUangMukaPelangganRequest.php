<?php

namespace App\Http\Requests;

use App\Models\FakturPenjualan;
use Illuminate\Foundation\Http\FormRequest;

class PakaiUangMukaPelangganRequest extends FormRequest
{
    public function authorize(): bool
    {
        $faktur = $this->route('faktur');

        return $faktur instanceof FakturPenjualan && ($this->user()?->can('terimaPembayaran', $faktur) ?? false);
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'uang_muka_id' => ['required', 'integer', 'exists:wms_uang_muka_pelanggan,id'],
            'jumlah' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'uang_muka_id' => 'uang muka pelanggan',
            'jumlah' => 'nilai pemakaian',
        ];
    }
}
