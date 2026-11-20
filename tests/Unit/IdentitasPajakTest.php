<?php

namespace Tests\Unit;

use App\Rules\NikValid;
use App\Rules\NitkuValid;
use App\Rules\NpwpValid;
use App\Rules\NsfpValid;
use App\Support\IdentitasPajak;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IdentitasPajakTest extends TestCase
{
    public function test_cleaning_strips_dots_dashes_and_spaces_only(): void
    {
        $this->assertSame('0123456789012000', IdentitasPajak::bersihkan(' 01.2345-6789 012 000 '));
        $this->assertNull(IdentitasPajak::bersihkan('   '));
        $this->assertNull(IdentitasPajak::bersihkan(null));
        $this->assertSame('12AB', IdentitasPajak::bersihkan('12-AB'));
    }

    public function test_an_old_fifteen_digit_npwp_is_normalised_with_a_leading_zero(): void
    {
        $this->assertSame('0012345678901000', IdentitasPajak::normalisasiNpwp('01.234.567.8-901.000'));
        $this->assertSame('0123456789012000', IdentitasPajak::normalisasiNpwp('0123456789012000'));
        $this->assertSame('01234567890123', IdentitasPajak::normalisasiNpwp('01234567890123'));
    }

    #[DataProvider('npwp')]
    public function test_npwp_validity(?string $nilai, bool $diharapkan): void
    {
        $this->assertSame($diharapkan, IdentitasPajak::npwpValid($nilai));
    }

    public static function npwp(): array
    {
        return [
            'enam belas digit' => ['0123456789012000', true],
            'lima belas digit lama' => ['012345678901000', true],
            'lima belas dengan titik dan strip' => ['01.234.567.8-901.000', true],
            'empat belas digit' => ['01234567890123', false],
            'tujuh belas digit' => ['01234567890123456', false],
            'berisi huruf' => ['01234567890123AB', false],
            'kosong' => ['', false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('nik')]
    public function test_nik_validity(?string $nilai, bool $diharapkan): void
    {
        $this->assertSame($diharapkan, IdentitasPajak::nikValid($nilai));
    }

    public static function nik(): array
    {
        return [
            'enam belas digit' => ['3174061502560010', true],
            'lima belas digit' => ['317406150256001', false],
            'tujuh belas digit' => ['31740615025600101', false],
            'berisi huruf' => ['317406150256001X', false],
            'kosong' => [null, false],
        ];
    }

    #[DataProvider('nitku')]
    public function test_nitku_validity_is_length_and_digits_only(?string $nilai, bool $diharapkan): void
    {
        $this->assertSame($diharapkan, IdentitasPajak::nitkuValid($nilai));
    }

    public static function nitku(): array
    {
        return [
            'dua puluh dua digit' => ['1091031210912281000000', true],
            'dua puluh satu digit' => ['109103121091228100000', false],
            'dua puluh tiga digit' => ['10910312109122810000000', false],
            'berisi huruf' => ['109103121091228100000A', false],
            'kosong' => ['', false],
        ];
    }

    #[DataProvider('nsfp')]
    public function test_nsfp_accepts_the_old_format_and_seventeen_digits(?string $nilai, bool $diharapkan): void
    {
        $this->assertSame($diharapkan, IdentitasPajak::nsfpValid($nilai));
    }

    public static function nsfp(): array
    {
        return [
            'format lama' => ['010.001-23.12345678', true],
            'tujuh belas digit' => ['04002600000101012', true],
            'enam belas digit' => ['0400260000010101', false],
            'delapan belas digit' => ['040026000001010123', false],
            'tujuh belas dengan strip' => ['04-00-2600000101012', false],
            'format lama kurang satu digit' => ['010.001-23.1234567', false],
            'berisi huruf' => ['0400260000010101A', false],
            'kosong' => ['', false],
            'null' => [null, false],
        ];
    }

    public function test_the_rule_classes_delegate_to_the_shared_helper(): void
    {
        $gagal = [];
        $catat = function (string $pesan) use (&$gagal) {
            $gagal[] = $pesan;
        };

        (new NpwpValid())->validate('npwp', '01234567890123', $catat);
        (new NikValid())->validate('nik', '123', $catat);
        (new NitkuValid())->validate('nitku', '123', $catat);
        (new NsfpValid())->validate('nsfp', '123', $catat);

        $this->assertCount(4, $gagal);

        $gagal = [];
        (new NpwpValid())->validate('npwp', '012345678901000', $catat);
        (new NikValid())->validate('nik', '3174061502560010', $catat);
        (new NitkuValid())->validate('nitku', '1091031210912281000000', $catat);
        (new NsfpValid())->validate('nsfp', '04002600000101012', $catat);

        $this->assertSame([], $gagal);
    }
}
