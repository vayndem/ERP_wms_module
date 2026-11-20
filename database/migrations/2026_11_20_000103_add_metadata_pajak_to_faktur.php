<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplikat = DB::table('wms_faktur_penjualan')
            ->whereNotNull('no_faktur_pajak')
            ->groupBy('no_faktur_pajak')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('no_faktur_pajak');

        if ($duplikat->isNotEmpty()) {
            throw new RuntimeException(
                'NSFP ganda pada wms_faktur_penjualan harus dibereskan dulu sebelum unique index dibuat: '
                . $duplikat->implode(', ')
            );
        }

        Schema::table('wms_faktur_penjualan', function (Blueprint $table) {
            $table->date('tanggal_faktur_pajak')->nullable()->after('no_faktur_pajak');
            $table->decimal('ppnbm', 18, 2)->default(0)->after('total_ppn');
            $table->string('pembeli_nama', 191)->nullable()->after('diposting_pada');
            $table->string('pembeli_npwp', 50)->nullable()->after('pembeli_nama');
            $table->string('pembeli_nik', 16)->nullable()->after('pembeli_npwp');
            $table->string('pembeli_nitku', 22)->nullable()->after('pembeli_nik');
            $table->text('pembeli_alamat')->nullable()->after('pembeli_nitku');
            $table->unique('no_faktur_pajak', 'faktur_penjualan_nsfp_unique');
        });

        DB::statement(
            "UPDATE wms_faktur_penjualan f JOIN pelanggans p ON p.id = f.pelanggan_id
             SET f.pembeli_nama = p.nama, f.pembeli_npwp = p.npwp, f.pembeli_nik = p.nik,
                 f.pembeli_nitku = p.nitku, f.pembeli_alamat = p.alamat
             WHERE f.status <> 'DRAFT'"
        );

        Schema::table('wms_faktur_pembelian', function (Blueprint $table) {
            $table->date('tanggal_faktur_pajak')->nullable()->after('no_faktur_pajak');
            $table->decimal('ppnbm', 18, 2)->default(0)->after('ppn');
            $table->string('kode_objek_pajak', 12)->nullable()->after('jenis_pph');
            $table->foreign('kode_objek_pajak', 'faktur_pembelian_kop_fk')
                ->references('kode')->on('wms_kode_objek_pajak')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wms_faktur_pembelian', function (Blueprint $table) {
            $table->dropForeign('faktur_pembelian_kop_fk');
            $table->dropColumn(['tanggal_faktur_pajak', 'ppnbm', 'kode_objek_pajak']);
        });

        Schema::table('wms_faktur_penjualan', function (Blueprint $table) {
            $table->dropUnique('faktur_penjualan_nsfp_unique');
            $table->dropColumn([
                'tanggal_faktur_pajak', 'ppnbm', 'pembeli_nama', 'pembeli_npwp',
                'pembeli_nik', 'pembeli_nitku', 'pembeli_alamat',
            ]);
        });
    }
};
