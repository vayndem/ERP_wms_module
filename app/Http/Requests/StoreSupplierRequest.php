<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MengaturIdentitasPajak;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\Supplier;

class StoreSupplierRequest extends FormRequest
{
    use MengaturIdentitasPajak;

    public function authorize(): bool
    {
        return $this->user()->can('create', Supplier::class);
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
