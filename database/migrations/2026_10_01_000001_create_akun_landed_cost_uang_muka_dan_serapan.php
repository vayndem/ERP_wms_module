<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const AKUN = [
        'BIAYA_MASIH_HARUS_DIBAYAR' => ['2111', 'Biaya Masih Harus Dibayar', 'LIABILITAS', 'KREDIT'],
        'UANG_MUKA_PELANGGAN' => ['2112', 'Uang Muka Pelanggan', 'LIABILITAS', 'KREDIT'],
        'BEBAN_TENAGA_KERJA_DISERAP' => ['5203', 'Beban Tenaga Kerja Langsung Diserap', 'BEBAN', 'KREDIT'],
        'BEBAN_OVERHEAD_DISERAP' => ['5204', 'Beban Overhead Pabrik Diserap', 'BEBAN', 'KREDIT'],
    ];

    public function up(): void
    {
        foreach (self::AKUN as $kunci => [$kode, $nama, $kategori, $posisi]) {
            if (DB::table('accounting_settings')->where('key', $kunci)->exists()) {
                continue;
            }

            $akun = DB::table('wms_bagan_akun')->where('kode_akun', $kode)->first();

            if ($akun && $akun->nama_akun !== $nama) {
                throw new RuntimeException("Kode akun {$kode} sudah dipakai oleh {$akun->nama_akun}; tentukan kode lain untuk {$nama}.");
            }

            if (!$akun) {
                DB::table('wms_bagan_akun')->insert([
                    'kode_akun' => $kode,
                    'nama_akun' => $nama,
                    'kategori_akun' => $kategori,
                    'posisi_normal' => $posisi,
                    'is_active' => true,
                    'is_postable' => true,
                    'is_cash_bank' => false,
                    'klasifikasi_fiskal' => 'NONE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('accounting_settings')->insert([
                'key' => $kunci,
                'coa_id' => DB::table('wms_bagan_akun')->where('kode_akun', $kode)->value('id'),
                'description' => 'Mapping sistem WMS; nama dan kode akun tetap dapat disesuaikan accountant.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::AKUN as $kunci => [$kode]) {
            DB::table('accounting_settings')->where('key', $kunci)->delete();
            DB::table('wms_bagan_akun')->where('kode_akun', $kode)->delete();
        }
    }
};
