<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEKS = [
        ['wms_pembayaran_faktur', 'pembayaran_faktur_status_tgl_idx', ['status', 'tanggal_pembayaran']],
        ['wms_jurnal', 'jurnal_tanggal_idx', ['tanggal']],
        ['wms_faktur_pembelian', 'faktur_pembelian_supplier_nsfp_idx', ['kode_supplier', 'no_faktur_pajak']],
        ['wms_bukti_potong', 'bp_status_tgl_potong_idx', ['status', 'tanggal_pemotongan']],
    ];

    public function up(): void
    {
        foreach (self::INDEKS as [$tabel, $nama, $kolom]) {
            if (Schema::hasIndex($tabel, $nama)) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $table) use ($kolom, $nama) {
                $table->index($kolom, $nama);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEKS as [$tabel, $nama]) {
            if (!Schema::hasIndex($tabel, $nama)) {
                continue;
            }

            Schema::table($tabel, function (Blueprint $table) use ($nama) {
                $table->dropIndex($nama);
            });
        }
    }
};
