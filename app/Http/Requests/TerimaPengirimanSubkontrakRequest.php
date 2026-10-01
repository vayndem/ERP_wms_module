<?php

namespace App\Http\Requests;

use App\Models\PengirimanSubkontrak;
use Illuminate\Foundation\Http\FormRequest;

class TerimaPengirimanSubkontrakRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pengiriman = $this->route('subkontrak');

        return $pengiriman instanceof PengirimanSubkontrak
            && ($this->user()?->can('terima', $pengiriman) ?? false);
    }

    public function rules(): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'kembali' => ['required', 'array', 'min:1'],
            'kembali.*' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
