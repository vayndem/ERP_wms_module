<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_bukti_potong', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pembayaran_id')->constrained('wms_pembayaran_faktur', 'id', 'bp_pembayaran_fk')->restrictOnDelete();
            $table->unsignedSmallInteger('urutan')->default(1);
            $table->unsignedSmallInteger('versi')->default(1);
            $table->foreignId('supplier_id')->constrained('suppliers', 'id', 'bp_supplier_fk')->restrictOnDelete();
            $table->string('jenis_pph', 20);
            $table->string('kode_objek_pajak', 12)->nullable();
            $table->unsignedTinyInteger('masa_pajak');
            $table->unsignedSmallInteger('tahun_pajak');
            $table->date('tanggal_pemotongan');
            $table->decimal('dasar_pengenaan', 18, 2);
            $table->decimal('tarif', 8, 4);
            $table->decimal('jumlah_pph', 18, 2);
            $table->string('penerima_nama', 200);
            $table->string('penerima_npwp', 50)->nullable();
            $table->string('penerima_nik', 30)->nullable();
            $table->string('penerima_nitku', 30)->nullable();
            $table->string('penerima_alamat', 300)->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->boolean('perlu_pembatalan')->default(false);
            $table->string('catatan', 300)->nullable();
            $table->string('nomor_bukti_djp', 60)->nullable();
            $table->date('tanggal_diterbitkan')->nullable();
            $table->foreignId('menggantikan_id')->nullable()->constrained('wms_bukti_potong', 'id', 'bp_menggantikan_fk')->nullOnDelete();
            $table->foreignId('dibuat_oleh')->nullable()->constrained('users', 'id', 'bp_dibuat_oleh_fk')->nullOnDelete();
            $table->foreignId('diterbitkan_oleh')->nullable()->constrained('users', 'id', 'bp_terbit_oleh_fk')->nullOnDelete();
            $table->timestamp('diterbitkan_pada')->nullable();
            $table->foreignId('dibatalkan_oleh')->nullable()->constrained('users', 'id', 'bp_batal_oleh_fk')->nullOnDelete();
            $table->timestamp('dibatalkan_pada')->nullable();
            $table->timestamps();

            $table->unique(['pembayaran_id', 'urutan', 'versi'], 'bp_pembayaran_versi_unique');
            $table->unique('nomor_bukti_djp', 'bp_nomor_djp_unique');
            $table->index(['tahun_pajak', 'masa_pajak', 'jenis_pph'], 'bp_masa_jenis_index');
            $table->index('status', 'bp_status_index');
            $table->foreign('kode_objek_pajak', 'bp_kode_objek_fk')->references('kode')->on('wms_kode_objek_pajak')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wms_bukti_potong');
    }
};
