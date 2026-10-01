<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_pesanan_penjualan', function (Blueprint $table) {
            $table->boolean('plafon_dilampaui')->default(false)->after('status');
            $table->text('alasan_plafon')->nullable()->after('plafon_dilampaui');
            $table->unsignedBigInteger('plafon_disetujui_oleh')->nullable()->after('alasan_plafon');
            $table->decimal('eksposur_saat_dibuat', 18, 2)->nullable()->after('plafon_disetujui_oleh');

            $table->foreign('plafon_disetujui_oleh')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wms_pesanan_penjualan', function (Blueprint $table) {
            $table->dropForeign(['plafon_disetujui_oleh']);
            $table->dropColumn(['plafon_dilampaui', 'alasan_plafon', 'plafon_disetujui_oleh', 'eksposur_saat_dibuat']);
        });
    }
};
