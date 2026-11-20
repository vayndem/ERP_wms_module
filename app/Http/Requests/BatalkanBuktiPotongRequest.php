<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BatalkanBuktiPotongRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'alasan' => 'required|string|min:5|max:300',
        ];
    }
}
