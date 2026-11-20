<?php

namespace App\Rules;

use App\Support\IdentitasPajak;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NsfpValid implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!IdentitasPajak::nsfpValid((string) $value)) {
            $fail('Nomor seri faktur pajak harus 17 digit angka atau format lama 000.000-00.00000000.');
        }
    }
}
