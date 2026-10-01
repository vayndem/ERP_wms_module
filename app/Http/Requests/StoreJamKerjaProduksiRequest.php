<?php

namespace App\Http\Requests;

use App\Models\DataPesanan;
use Illuminate\Foundation\Http\FormRequest;

class StoreJamKerjaProduksiRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pesanan = $this->route('pesanan');

        return $pesanan instanceof DataPesanan && ($this->user()?->can('catatJamKerja', $pesanan) ?? false);
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'pusat_kerja_id' => ['required', 'integer', 'exists:wms_pusat_kerja,id'],
            'jam' => ['required', 'numeric', 'gt:0', 'max:9999'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return [
            'pusat_kerja_id' => 'pusat kerja',
            'jam' => 'jam kerja',
        ];
    }
}
