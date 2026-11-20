<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_kode_objek_pajak', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 12);
            $table->string('jenis_pph', 20);
            $table->string('deskripsi', 300);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique('kode', 'kop_kode_unique');
            $table->index('jenis_pph', 'kop_jenis_pph_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_kode_objek_pajak');
    }
};
