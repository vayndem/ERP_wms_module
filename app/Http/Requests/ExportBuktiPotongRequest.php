<?php

namespace App\Http\Requests;

use App\Services\BuktiPotongExportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportBuktiPotongRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'masa' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'status' => ['required', Rule::in(BuktiPotongExportService::STATUS_PILIHAN)],
        ];
    }
}
