<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TerbitkanBuktiPotongRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'nomor_bukti_djp' => 'required|string|max:60',
            'tanggal_diterbitkan' => 'nullable|date|before_or_equal:today',
        ];
    }

    public function attributes(): array
    {
        return [
            'nomor_bukti_djp' => 'nomor bukti potong dari Coretax',
            'tanggal_diterbitkan' => 'tanggal diterbitkan',
        ];
    }
}
