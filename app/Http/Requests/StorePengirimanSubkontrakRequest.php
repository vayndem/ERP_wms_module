<?php

namespace App\Http\Requests;

use App\Models\PengirimanSubkontrak;
use Illuminate\Foundation\Http\FormRequest;

class StorePengirimanSubkontrakRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', PengirimanSubkontrak::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'supplier_id' => ['required', 'integer', 'exists:suppliers,id'],
            'gudang_asal_id' => ['required', 'integer', 'exists:gudangs,id'],
            'estimasi_kembali' => ['nullable', 'date', 'after_or_equal:tanggal'],
            'keperluan' => ['nullable', 'string', 'max:191'],
            'keterangan' => ['nullable', 'string', 'max:1000'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.bahan_id' => ['required', 'integer', 'exists:bahans,id'],
            'details.*.jumlah' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
