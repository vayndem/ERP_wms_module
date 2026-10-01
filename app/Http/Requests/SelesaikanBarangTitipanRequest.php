<?php

namespace App\Http\Requests;

use App\Models\BarangTitipan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SelesaikanBarangTitipanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('operateWarehouse') || Gate::allows('viewWmsControl');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in([
                BarangTitipan::DIKEMBALIKAN,
                BarangTitipan::DIBELI,
                BarangTitipan::HILANG,
            ])],
            'tanggal_kembali' => ['nullable', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'status' => 'status penyelesaian',
            'tanggal_kembali' => 'tanggal kembali',
        ];
    }
}
