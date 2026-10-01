<?php

namespace Tests\Feature;

use App\Models\Bahan;
use App\Models\Bom;
use App\Models\Gudang;
use App\Models\Pelanggan;
use App\Models\PusatKerja;
use App\Models\StokGudang;
use App\Models\User;
use App\Services\BomService;
use App\Services\DataPesananService;
use App\Services\PenjualanService;
use App\Services\RoutingProduksiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class RoutingProduksiTest extends TestCase
{
    use DatabaseTransactions;

    private function produksi(): User
    {
        return User::factory()->create(['type' => User::ROLE_PRODUCTION, 'is_active' => true]);
    }

    private function pusatKerja(User $user, string $kode, ?float $kapasitas = 480): PusatKerja
    {
        return app(RoutingProduksiService::class)->simpanPusatKerja([
            'kode' => $kode . random_int(100, 999),
            'nama' => 'Pusat ' . $kode,
            'kapasitas_menit_per_hari' => $kapasitas,
        ], $user);
    }

    private function bom(User $user, Bahan $produk): Bom
    {
        Bom::where('bahan_id', $produk->id)->update(['status' => Bom::NONAKTIF]);

        $komponen = Bahan::whereKeyNot($produk->id)->firstOrFail();

        return app(BomService::class)->buat([
            'kode' => 'BOM-RT-' . random_int(1000, 9999),
            'nama' => 'BOM routing',
            'bahan_id' => $produk->id,
            'versi' => 'R' . random_int(100, 999),
            'jumlah_hasil' => 1,
            'details' => [['bahan_id' => $komponen->id, 'jumlah' => 1]],
        ], $user);
    }

    public function test_a_routing_is_stored_in_sequence_and_scales_with_the_work_order(): void
    {
        $user = $this->produksi();
        $stok = StokGudang::whereRaw('stok_tersedia - stok_direservasi >= 3')
            ->whereHas('gudang', fn ($q) => $q->where('jenis', Gudang::NORMAL))->firstOrFail();
        $produk = Bahan::whereKeyNot($stok->bahan_id)->firstOrFail();

        $bom = $this->bom($user, $produk);
        $potong = $this->pusatKerja($user, 'POTONG');
        $rakit = $this->pusatKerja($user, 'RAKIT');

        $service = app(RoutingProduksiService::class);

        $service->simpanOperasi($bom, [
            ['pusat_kerja_id' => $rakit->id, 'urutan' => 2, 'nama_operasi' => 'Perakitan', 'waktu_standar_menit' => 20],
            ['pusat_kerja_id' => $potong->id, 'urutan' => 1, 'nama_operasi' => 'Pemotongan', 'waktu_standar_menit' => 5],
        ]);

        $operasi = $bom->fresh('operasi')->operasi->sortBy('urutan')->values();

        $this->assertSame('Pemotongan', $operasi[0]->nama_operasi, 'Operasi wajib tersimpan menurut nomor urut, bukan urutan input.');
        $this->assertSame('Perakitan', $operasi[1]->nama_operasi);

        $pelanggan = Pelanggan::create([
            'kode' => 'CUST-RT-' . random_int(1000, 9999),
            'nama' => 'PT Uji Routing',
            'termin_hari' => 30,
            'is_active' => true,
        ]);

        $pesanan = app(PenjualanService::class)->buatPesanan([
            'tanggal' => today()->toDateString(),
            'pelanggan_id' => $pelanggan->id,
            'gudang_id' => $stok->gudang_id,
            'is_ppn' => false,
            'details' => [['bahan_id' => $produk->id, 'jumlah' => 3, 'harga_satuan' => 100000]],
        ], $user);

        $wo = app(DataPesananService::class)->buat($pesanan->details->first(), [
            'tanggal' => today()->toDateString(),
            'gudang_id' => $stok->gudang_id,
            'jumlah_rencana' => 3,
        ], $user);

        $routing = $service->routing($wo->fresh());

        $this->assertEqualsWithDelta(3, $routing['basis'], 0.000001);
        $this->assertEqualsWithDelta(75, $routing['total_menit'], 0.01, '(5 + 20) menit per unit × 3 unit = 75 menit.');
    }

    public function test_a_routing_refuses_duplicate_sequence_numbers(): void
    {
        $user = $this->produksi();
        $produk = Bahan::firstOrFail();
        $bom = $this->bom($user, $produk);
        $pusat = $this->pusatKerja($user, 'DUP');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak boleh kembar');

        app(RoutingProduksiService::class)->simpanOperasi($bom, [
            ['pusat_kerja_id' => $pusat->id, 'urutan' => 1, 'nama_operasi' => 'A', 'waktu_standar_menit' => 1],
            ['pusat_kerja_id' => $pusat->id, 'urutan' => 1, 'nama_operasi' => 'B', 'waktu_standar_menit' => 1],
        ]);
    }

    public function test_an_inactive_work_centre_cannot_be_routed_to_and_cannot_be_retired_while_in_use(): void
    {
        $user = $this->produksi();
        $produk = Bahan::firstOrFail();
        $bom = $this->bom($user, $produk);
        $service = app(RoutingProduksiService::class);

        $pusat = $this->pusatKerja($user, 'PAKAI');
        $service->simpanOperasi($bom, [
            ['pusat_kerja_id' => $pusat->id, 'urutan' => 1, 'nama_operasi' => 'Proses', 'waktu_standar_menit' => 10],
        ]);

        try {
            $service->ubahStatusPusatKerja($pusat, PusatKerja::NONAKTIF);
            $this->fail('Pusat kerja yang masih dipakai routing seharusnya tidak bisa dinonaktifkan.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('masih dipakai', $e->getMessage());
        }

        $lain = $this->pusatKerja($user, 'MATI');
        $service->ubahStatusPusatKerja($lain, PusatKerja::NONAKTIF);

        $bomLain = $this->bom($user, Bahan::whereKeyNot($produk->id)->firstOrFail());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nonaktif tidak dapat dipakai');

        $service->simpanOperasi($bomLain, [
            ['pusat_kerja_id' => $lain->id, 'urutan' => 1, 'nama_operasi' => 'Proses', 'waktu_standar_menit' => 10],
        ]);
    }

    public function test_a_work_centre_cannot_claim_more_than_a_day_of_capacity(): void
    {
        $user = $this->produksi();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('1.440 menit');

        app(RoutingProduksiService::class)->simpanPusatKerja([
            'kode' => 'OVER-' . random_int(100, 999),
            'nama' => 'Kapasitas mustahil',
            'kapasitas_menit_per_hari' => 2000,
        ], $user);
    }

    public function test_routing_carries_no_cost_because_labour_absorption_is_not_built(): void
    {
        $user = $this->produksi();
        $produk = Bahan::firstOrFail();
        $bom = $this->bom($user, $produk);
        $pusat = $this->pusatKerja($user, 'BIAYA');

        app(RoutingProduksiService::class)->simpanOperasi($bom, [
            ['pusat_kerja_id' => $pusat->id, 'urutan' => 1, 'nama_operasi' => 'Proses', 'waktu_standar_menit' => 30],
        ]);

        $kolom = \Illuminate\Support\Facades\Schema::getColumnListing('wms_bom_operasi');

        foreach (['tarif', 'biaya', 'rate', 'cost'] as $kata) {
            $this->assertEmpty(
                array_filter($kolom, fn ($c) => str_contains($c, $kata)),
                "Tarif hidup di pusat kerja, bukan di baris routing: satu operasi dinilai dari tarif pusat kerjanya saat jam kerja dicatat (sejak 2026-10-01)."
            );
        }
    }

    public function test_the_routing_page_is_open_to_production_and_closed_to_finance(): void
    {
        $this->actingAs($this->produksi())->get(route('routing-produksi.index'))->assertOk();

        $this->actingAs(User::factory()->create(['type' => User::ROLE_FINANCE, 'is_active' => true]))
            ->get(route('routing-produksi.index'))
            ->assertForbidden();
    }

    public function test_saving_an_empty_routing_cannot_silently_wipe_an_existing_one(): void
    {
        $user = $this->produksi();
        $produk = Bahan::firstOrFail();
        $bom = $this->bom($user, $produk);
        $pusat = $this->pusatKerja($user, 'UTUH');
        $service = app(RoutingProduksiService::class);

        $service->simpanOperasi($bom, [
            ['pusat_kerja_id' => $pusat->id, 'urutan' => 1, 'nama_operasi' => 'Proses', 'waktu_standar_menit' => 10],
        ]);

        $this->actingAs($user)
            ->from(route('routing-produksi.index'))
            ->post(route('routing-produksi.operasi', $bom), ['operasi' => []])
            ->assertSessionHasErrors('operasi');

        $this->assertSame(
            1,
            $bom->fresh()->operasi()->count(),
            'Simpan routing mengganti seluruh urutan operasi, jadi kiriman kosong harus ditolak dan bukan menghapus routing yang sudah ada.'
        );

        try {
            $service->simpanOperasi($bom->fresh(), []);
            $this->fail('Service seharusnya menolak routing kosong, bukan hanya form request-nya.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('minimal satu operasi', $e->getMessage());
        }

        $this->assertSame(1, $bom->fresh()->operasi()->count());
    }
}
