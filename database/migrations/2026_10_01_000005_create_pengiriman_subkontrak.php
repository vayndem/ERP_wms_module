<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wms_pengiriman_subkontrak', function (Blueprint $table) {
            $table->id();
            $table->string('nomor', 50)->unique();
            $table->date('tanggal');
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('gudang_asal_id');
            $table->unsignedBigInteger('gudang_subkontrak_id');
            $table->foreign('supplier_id', 'subkontrak_supplier_fk')->references('id')->on('suppliers')->restrictOnDelete();
            $table->foreign('gudang_asal_id', 'subkontrak_gudang_asal_fk')->references('id')->on('gudangs')->restrictOnDelete();
            $table->foreign('gudang_subkontrak_id', 'subkontrak_gudang_tujuan_fk')->references('id')->on('gudangs')->restrictOnDelete();
            $table->date('estimasi_kembali')->nullable();
            $table->string('status', 20)->default('DIKIRIM');
            $table->string('keperluan', 191)->nullable();
            $table->text('keterangan')->nullable();
            $table->unsignedBigInteger('transfer_keluar_id')->nullable();
            $table->unsignedBigInteger('dibuat_oleh')->nullable();
            $table->timestamps();

            $table->foreign('dibuat_oleh', 'subkontrak_pembuat_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('transfer_keluar_id', 'subkontrak_transfer_fk')->references('id')->on('transfer_gudangs')->nullOnDelete();
            $table->index(['status', 'estimasi_kembali'], 'subkontrak_status_index');
        });

        Schema::create('wms_pengiriman_subkontrak_detail', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pengiriman_subkontrak_id');
            $table->unsignedBigInteger('bahan_id');
            $table->foreign('pengiriman_subkontrak_id', 'subkontrak_detail_induk_fk')
                ->references('id')->on('wms_pengiriman_subkontrak')->cascadeOnDelete();
            $table->foreign('bahan_id', 'subkontrak_detail_bahan_fk')
                ->references('id')->on('bahans')->restrictOnDelete();
            $table->decimal('jumlah', 18, 6);
            $table->decimal('jumlah_kembali', 18, 6)->default(0);
            $table->timestamps();

            $table->unique(['pengiriman_subkontrak_id', 'bahan_id'], 'subkontrak_detail_unique');
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('wms_pengiriman_subkontrak_detail');
        Schema::dropIfExists('wms_pengiriman_subkontrak');
    }
};
