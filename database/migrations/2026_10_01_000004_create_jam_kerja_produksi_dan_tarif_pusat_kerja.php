<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_pusat_kerja', function (Blueprint $table) {
            $table->decimal('tarif_tenaga_kerja_per_jam', 18, 2)->default(0)->after('kapasitas_menit_per_hari');
            $table->decimal('tarif_overhead_per_jam', 18, 2)->default(0)->after('tarif_tenaga_kerja_per_jam');
        });

        Schema::create('wms_jam_kerja_produksi', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 50)->unique();
            $table->date('tanggal');
            $table->foreignId('data_pesanan_id')->constrained('wms_data_pesanan')->restrictOnDelete();
            $table->foreignId('pusat_kerja_id')->constrained('wms_pusat_kerja')->restrictOnDelete();
            $table->decimal('jam', 18, 2);
            $table->decimal('tarif_tenaga_kerja_per_jam', 18, 2);
            $table->decimal('tarif_overhead_per_jam', 18, 2);
            $table->decimal('biaya_tenaga_kerja', 18, 2);
            $table->decimal('biaya_overhead', 18, 2);
            $table->string('keterangan', 255)->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->foreign('journal_id')->references('id')->on('wms_jurnal')->nullOnDelete();
            $table->index(['data_pesanan_id', 'tanggal'], 'jam_kerja_pesanan_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_jam_kerja_produksi');

        Schema::table('wms_pusat_kerja', function (Blueprint $table) {
            $table->dropColumn(['tarif_tenaga_kerja_per_jam', 'tarif_overhead_per_jam']);
        });
    }
};
