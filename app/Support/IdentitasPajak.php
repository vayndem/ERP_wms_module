<?php

namespace App\Support;

final class IdentitasPajak
{
    public const PANJANG_NPWP = 16;
    public const PANJANG_NPWP_LAMA = 15;
    public const PANJANG_NIK = 16;
    public const PANJANG_NITKU = 22;

    public const NSFP_FORMAT_LAMA = '/^\d{3}\.\d{3}-\d{2}\.\d{8}$/';
    public const NSFP_FORMAT_17_DIGIT = '/^\d{17}$/';

    public static function bersihkan(?string $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $hasil = str_replace(['.', '-', ' '], '', trim($nilai));

        return $hasil === '' ? null : $hasil;
    }

    public static function normalisasiNpwp(?string $nilai): ?string
    {
        $hasil = self::bersihkan($nilai);

        if ($hasil !== null && ctype_digit($hasil) && strlen($hasil) === self::PANJANG_NPWP_LAMA) {
            return '0' . $hasil;
        }

        return $hasil;
    }

    public static function npwpValid(?string $nilai): bool
    {
        return self::digitPanjang(self::normalisasiNpwp($nilai), self::PANJANG_NPWP);
    }

    public static function nikValid(?string $nilai): bool
    {
        return self::digitPanjang(self::bersihkan($nilai), self::PANJANG_NIK);
    }

    public static function nitkuValid(?string $nilai): bool
    {
        return self::digitPanjang(self::bersihkan($nilai), self::PANJANG_NITKU);
    }

    public static function nsfpValid(?string $nilai): bool
    {
        $nilai = trim((string) $nilai);

        return preg_match(self::NSFP_FORMAT_LAMA, $nilai) === 1
            || preg_match(self::NSFP_FORMAT_17_DIGIT, $nilai) === 1;
    }

    private static function digitPanjang(?string $nilai, int $panjang): bool
    {
        return $nilai !== null && strlen($nilai) === $panjang && ctype_digit($nilai);
    }
}
