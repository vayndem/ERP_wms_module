<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MasaBuktiPotongRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'masa' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'status' => 'nullable|in:DRAFT,DITERBITKAN,DIBATALKAN,DIGANTI,SEMUA',
        ];
    }

    public function masa(): string
    {
        return (string) ($this->validated()['masa'] ?? today()->format('Y-m'));
    }

    public function statusFilter(): ?string
    {
        $status = $this->validated()['status'] ?? null;

        return $status === 'SEMUA' ? null : $status;
    }
}
