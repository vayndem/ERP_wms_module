<?php

namespace App\Http\Requests;

use App\Models\PusatKerja;
use Illuminate\Foundation\Http\FormRequest;

class StorePusatKerjaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PusatKerja::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'kode' => ['required', 'string', 'max:30', 'unique:wms_pusat_kerja,kode'],
            'nama' => ['required', 'string', 'max:191'],
            'gudang_id' => ['nullable', 'integer', 'exists:gudangs,id'],
            'kapasitas_menit_per_hari' => ['nullable', 'numeric', 'gt:0', 'max:1440'],
            'tarif_tenaga_kerja_per_jam' => ['nullable', 'numeric', 'min:0'],
            'tarif_overhead_per_jam' => ['nullable', 'numeric', 'min:0'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
