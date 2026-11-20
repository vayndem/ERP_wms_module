<?php

namespace Database\Seeders;

use App\Models\FakturPembelian;
use App\Models\FakturPenjualan;
use App\Models\KodeObjekPajak;
use Illuminate\Database\Seeder;

class MetadataPajakDemoSeeder extends Seeder
{
    public function run(): void
    {
        FakturPenjualan::whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
            ->whereNull('tanggal_faktur_pajak')
            ->get()
            ->each(fn (FakturPenjualan $faktur) => $faktur->update(['tanggal_faktur_pajak' => $faktur->tanggal]));

        $kodePerJenis = collect(['PPH23' => '24-104-29', 'PPH4A2' => '28-403-02'])
            ->filter(fn ($kode) => KodeObjekPajak::aktif()->where('kode', $kode)->exists());

        FakturPembelian::where('status', '!=', FakturPembelian::VOID)->get()->each(function (FakturPembelian $faktur) use ($kodePerJenis) {
            $ubah = [];

            if ($faktur->jenis_pajak === 'PPN') {
                if (empty($faktur->no_faktur_pajak)) {
                    $ubah['no_faktur_pajak'] = '0100' . str_pad((string) $faktur->id, 13, '0', STR_PAD_LEFT);
                }

                if ($faktur->tanggal_faktur_pajak === null) {
                    $ubah['tanggal_faktur_pajak'] = $faktur->tanggal;
                }
            }

            if ($faktur->jenis_pph && empty($faktur->kode_objek_pajak) && $kodePerJenis->has($faktur->jenis_pph)) {
                $ubah['kode_objek_pajak'] = $kodePerJenis[$faktur->jenis_pph];
            }

            if ($ubah !== []) {
                $faktur->update($ubah);
            }
        });
    }
}
