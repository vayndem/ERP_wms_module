<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplikat = DB::table('wms_penyusutan_asets')
            ->select('aset_id', 'period_label', DB::raw('COUNT(*) as jumlah'))
            ->groupBy('aset_id', 'period_label')
            ->having('jumlah', '>', 1)
            ->get();

        if ($duplikat->isNotEmpty()) {
            $daftar = $duplikat->map(fn ($baris) => "aset_id {$baris->aset_id} / \"{$baris->period_label}\" ({$baris->jumlah} baris)")->implode('; ');

            throw new RuntimeException(
                "Penyusutan ganda ditemukan dan tidak dihapus otomatis karena tiap baris sudah dijurnal. Balik jurnal salah satunya terlebih dahulu: {$daftar}"
            );
        }

        Schema::table('wms_penyusutan_asets', function (Blueprint $table) {
            $table->unique(['aset_id', 'period_label'], 'penyusutan_aset_periode_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wms_penyusutan_asets', function (Blueprint $table) {
            $table->dropUnique('penyusutan_aset_periode_unique');
        });
    }
};
