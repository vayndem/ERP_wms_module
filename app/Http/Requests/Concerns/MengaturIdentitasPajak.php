<?php

namespace App\Http\Requests\Concerns;

use App\Rules\NikValid;
use App\Rules\NitkuValid;
use App\Rules\NpwpValid;
use App\Support\IdentitasPajak;
use Illuminate\Validation\Rule;

trait MengaturIdentitasPajak
{
    protected function normalisasiIdentitasPajak(): void
    {
        $this->merge([
            'npwp' => IdentitasPajak::normalisasiNpwp($this->input('npwp')),
            'nik' => IdentitasPajak::bersihkan($this->input('nik')),
            'nitku' => IdentitasPajak::bersihkan($this->input('nitku')),
            'tipe_identitas' => $this->input('tipe_identitas') ?: 'NPWP',
        ]);
    }

    protected function aturanIdentitasPajak(): array
    {
        return [
            'npwp' => ['nullable', 'string', new NpwpValid()],
            'nik' => ['nullable', 'string', new NikValid()],
            'nitku' => ['nullable', 'string', new NitkuValid()],
            'tipe_identitas' => ['nullable', 'string', Rule::in(['NPWP', 'NIK'])],
            'status_pkp' => ['nullable', 'boolean'],
        ];
    }
}
