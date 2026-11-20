<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\MengaturIdentitasPajak;
use App\Models\Pelanggan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePelangganRequest extends FormRequest
{
    use MengaturIdentitasPajak;

    public function authorize(): bool
    {
        $pelanggan = $this->route('pelanggan');

        return $pelanggan
            ? ($this->user()?->can('update', $pelanggan) ?? false)
            : ($this->user()?->can('create', Pelanggan::class) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->normalisasiIdentitasPajak();
    }

    public function rules(): array
    {
        $id = $this->route('pelanggan')?->id;

        return [
            'kode' => ['required', 'string', 'max:30', Rule::unique('pelanggans', 'kode')->ignore($id)],
            'nama' => ['required', 'string', 'max:191'],
            'telp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:191'],
            'alamat' => ['nullable', 'string', 'max:1000'],
            'up' => ['nullable', 'string', 'max:100'],
            'termin_hari' => ['required', 'integer', 'min:0', 'max:365'],
            'plafon_kredit' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ] + $this->aturanIdentitasPajak();
    }
}
