<?php

namespace App\Rules;

use App\Support\IdentitasPajak;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NitkuValid implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!IdentitasPajak::nitkuValid((string) $value)) {
            $fail('NITKU harus 22 digit angka.');
        }
    }
}
