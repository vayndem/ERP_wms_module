<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MengaturIdentitasPajak;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    use MengaturIdentitasPajak;

    public function authorize(): bool
    {
        $supplier = $this->route('supplier');
        return $this->user()->can('update', $supplier);
    }

    protected function prepareForValidation(): void
    {
        $this->normalisasiIdentitasPajak();
    }

    public function rules(): array
    {
        return [
            'nama'       => 'required|string|max:200',
            'alamat'     => 'required|string|max:200',
            'telp'       => 'required|string|max:50',
            'up'         => 'nullable|string|max:200',
            'pembayaran' => 'required|string|max:200',
        ] + $this->aturanIdentitasPajak();
    }
}
