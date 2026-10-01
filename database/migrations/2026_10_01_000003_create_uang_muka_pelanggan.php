<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_uang_muka_pelanggan', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 50)->unique();
            $table->date('tanggal');
            $table->foreignId('pelanggan_id')->constrained('pelanggans')->restrictOnDelete();
            $table->unsignedBigInteger('penerimaan_pembayaran_id')->nullable();
            $table->decimal('jumlah', 18, 2);
            $table->decimal('sisa', 18, 2);
            $table->string('status', 20)->default('AKTIF');
            $table->string('keterangan', 255)->nullable();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['pelanggan_id', 'status'], 'uang_muka_pelanggan_saldo_index');
        });

        Schema::table('wms_penerimaan_pembayaran', function (Blueprint $table) {
            $table->decimal('jumlah_uang_muka', 18, 2)->default(0)->after('jumlah');
            $table->unsignedBigInteger('uang_muka_id')->nullable()->after('jumlah_uang_muka');
            $table->unsignedBigInteger('coa_kas_bank_id')->nullable()->change();

            $table->foreign('uang_muka_id')->references('id')->on('wms_uang_muka_pelanggan')->nullOnDelete();
        });

        Schema::table('wms_uang_muka_pelanggan', function (Blueprint $table) {
            $table->foreign('penerimaan_pembayaran_id')->references('id')->on('wms_penerimaan_pembayaran')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wms_uang_muka_pelanggan', function (Blueprint $table) {
            $table->dropForeign(['penerimaan_pembayaran_id']);
        });

        Schema::table('wms_penerimaan_pembayaran', function (Blueprint $table) {
            $table->dropForeign(['uang_muka_id']);
            $table->dropColumn(['jumlah_uang_muka', 'uang_muka_id']);
        });

        Schema::dropIfExists('wms_uang_muka_pelanggan');
    }
};
