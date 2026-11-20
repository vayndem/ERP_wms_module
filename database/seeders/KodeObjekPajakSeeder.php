<?php

namespace Database\Seeders;

use App\Models\KodeObjekPajak;
use Illuminate\Database\Seeder;

class KodeObjekPajakSeeder extends Seeder
{
    public function run(): void
    {
        foreach (KodeObjekPajak::DAFTAR as $baris) {
            KodeObjekPajak::updateOrCreate(['kode' => $baris['kode']], $baris + ['is_active' => true]);
        }
    }
}
