<?php

namespace App\Rules;

use App\Support\IdentitasPajak;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NikValid implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!IdentitasPajak::nikValid((string) $value)) {
            $fail('NIK harus 16 digit angka.');
        }
    }
}
