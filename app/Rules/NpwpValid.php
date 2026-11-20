<?php

namespace App\Rules;

use App\Support\IdentitasPajak;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NpwpValid implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!IdentitasPajak::npwpValid((string) $value)) {
            $fail('NPWP harus 16 digit angka (NPWP lama 15 digit otomatis diberi awalan 0).');
        }
    }
}
