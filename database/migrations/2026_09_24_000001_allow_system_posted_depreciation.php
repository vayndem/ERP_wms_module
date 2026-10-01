<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wms_penyusutan_asets', function (Blueprint $table) {
            $table->unsignedBigInteger('posted_by')->nullable()
                ->comment('User yang memposting; null berarti diposting otomatis oleh penjadwal')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('wms_penyusutan_asets', function (Blueprint $table) {
            $table->unsignedBigInteger('posted_by')->nullable(false)
                ->comment('ID user lokal role Accounting yang memposting penyusutan')
                ->change();
        });
    }
};
