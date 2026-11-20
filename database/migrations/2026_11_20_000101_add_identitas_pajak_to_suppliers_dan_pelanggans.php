<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tabel = ['suppliers', 'pelanggans'];

    public function up(): void
    {
        foreach ($this->tabel as $nama) {
            Schema::table($nama, function (Blueprint $table) {
                $table->string('nik', 16)->nullable()->after('npwp');
                $table->string('nitku', 22)->nullable()->after('nik');
                $table->string('tipe_identitas', 10)->default('NPWP')->after('nitku');
                $table->boolean('status_pkp')->nullable()->after('tipe_identitas');
            });

            $this->normalisasiNpwp($nama);
        }
    }

    public function down(): void
    {
        foreach ($this->tabel as $nama) {
            Schema::table($nama, function (Blueprint $table) {
                $table->dropColumn(['nik', 'nitku', 'tipe_identitas', 'status_pkp']);
            });
        }
    }

    private function normalisasiNpwp(string $tabel): void
    {
        DB::table($tabel)->whereNotNull('npwp')->orderBy('id')->each(function ($baris) use ($tabel) {
            $digit = str_replace(['.', '-', ' '], '', trim((string) $baris->npwp));

            if (!ctype_digit($digit)) {
                return;
            }

            if (strlen($digit) === 15) {
                $digit = '0' . $digit;
            }

            if (strlen($digit) === 16 && $digit !== $baris->npwp) {
                DB::table($tabel)->where('id', $baris->id)->update(['npwp' => $digit]);
            }
        });
    }
};
