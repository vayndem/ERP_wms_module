<?php

namespace Tests\Feature;

use App\Models\Bahan;
use App\Models\Bom;
use App\Models\CrossDock;
use App\Models\DataPesanan;
use App\Models\Gudang;
use App\Models\PemakaianBarang;
use App\Models\Pelanggan;
use App\Models\PenerimaanBarang;
use App\Models\PenerimaanBarangDetail;
use App\Models\PesananPenjualan;
use App\Models\StokGudang;
use App\Models\User;
use App\Services\BomService;
use App\Services\CrossDockService;
use App\Services\DataPesananService;
use App\Services\DocumentNumberService;
use App\Services\PenjualanService;
use App\Services\StokGudangService;
use App\Services\WmsAccountingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CrossDockDanBomTest extends TestCase
{
    use DatabaseTransactions;

    private function operator(?int $gudangId = null): User
    {
        $user = User::factory()->create(['type' => User::ROLE_WAREHOUSE, 'is_active' => true]);

        if ($gudangId) {
            $user->pembagianGudangs()->create([
                'gudang_id' => $gudangId,
                'boleh_menerima' => true,
                'boleh_npk' => true,
                'boleh_transfer' => true,
                'boleh_opname' => true,
            ]);
        }

        return $user->fresh();
    }

    private function stok(float $minimal = 5): StokGudang
    {
        return StokGudang::whereRaw('stok_tersedia - stok_direservasi >= ?', [$minimal])
            ->whereHas('gudang', fn ($query) => $query->where('jenis', Gudang::NORMAL))
            ->firstOrFail();
    }

    private function penerimaanDetail(StokGudang $stok): PenerimaanBarangDetail
    {
        $lpb = PenerimaanBarang::where('document_type', 'GOODS')
            ->where('status', PenerimaanBarang::POSTED)
            ->where('gudang_id', $stok->gudang_id)
            ->firstOrFail();

        $lpb->update(['tanggal' => today()->subDay()]);

        $detail = PenerimaanBarangDetail::where('id_lpb', $lpb->id_lpb)
            ->where('id_bahan', $stok->bahan_id)
            ->first();

        if (!$detail) {
            $detail = PenerimaanBarangDetail::create([
                'id_lpb' => $lpb->id_lpb,
                'id_bahan' => $stok->bahan_id,
                'id_kategori' => Bahan::find($stok->bahan_id)?->id_kategori,
                'jumlah_barang_diterima' => 10,
                'harga' => 1000,
                'nilai_awal' => 10000,
                'jumlah_tersisa' => 10,
            ]);
        }

        return $detail;
    }

    private function pesananTerbuka(StokGudang $stok, User $user, float $jumlah = 3): PesananPenjualan
    {
        $pelanggan = Pelanggan::create([
            'kode' => 'CUST-XDK-' . random_int(1000, 9999),
            'nama' => 'PT Uji Cross Dock',
            'termin_hari' => 30,
            'is_active' => true,
        ]);

        return app(PenjualanService::class)->buatPesanan([
            'tanggal' => today()->toDateString(),
            'pelanggan_id' => $pelanggan->id,
            'gudang_id' => $stok->gudang_id,
            'is_ppn' => false,
            'details' => [['bahan_id' => $stok->bahan_id, 'jumlah' => $jumlah, 'harga_satuan' => 90000]],
        ], $user);
    }

    public function test_marking_a_cross_dock_reserves_the_stock_it_promises(): void
    {
        $stok = $this->stok();
        $user = $this->operator((int) $stok->gudang_id);
        $detail = $this->penerimaanDetail($stok);
        $pesanan = $this->pesananTerbuka($stok, $user);

        $sebelum = (float) $stok->fresh()->stok_direservasi;

        $crossDock = app(CrossDockService::class)->tandai([
            'penerimaan_barang_detail_id' => $detail->id,
            'pesanan_penjualan_detail_id' => $pesanan->details->first()->id,
            'jumlah' => 3,
        ], $user);

        $this->assertSame(CrossDock::DIRESERVASI, $crossDock->status);
        $this->assertNotNull($crossDock->reservasi_id);
        $this->assertEqualsWithDelta($sebelum + 3, (float) $stok->fresh()->stok_direservasi, 0.000001);
    }

    public function test_a_cross_dock_cannot_promise_more_than_the_order_still_needs(): void
    {
        $stok = $this->stok();
        $user = $this->operator((int) $stok->gudang_id);
        $detail = $this->penerimaanDetail($stok);
        $pesanan = $this->pesananTerbuka($stok, $user, 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('melebihi sisa pesanan');

        app(CrossDockService::class)->tandai([
            'penerimaan_barang_detail_id' => $detail->id,
            'pesanan_penjualan_detail_id' => $pesanan->details->first()->id,
            'jumlah' => 5,
        ], $user);
    }

    public function test_a_cross_dock_refuses_a_receipt_of_a_different_material(): void
    {
        $stok = $this->stok();
        $user = $this->operator((int) $stok->gudang_id);
        $lain = StokGudang::where('gudang_id', $stok->gudang_id)
            ->where('bahan_id', '!=', $stok->bahan_id)
            ->firstOrFail();

        $detail = $this->penerimaanDetail($stok);
        $pesanan = $this->pesananTerbuka($lain, $user, 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak sama');

        app(CrossDockService::class)->tandai([
            'penerimaan_barang_detail_id' => $detail->id,
            'pesanan_penjualan_detail_id' => $pesanan->details->first()->id,
            'jumlah' => 1,
        ], $user);
    }

    public function test_cancelling_a_cross_dock_gives_the_reserved_stock_back(): void
    {
        $stok = $this->stok();
        $user = $this->operator((int) $stok->gudang_id);
        $detail = $this->penerimaanDetail($stok);
        $pesanan = $this->pesananTerbuka($stok, $user);

        $sebelum = (float) $stok->fresh()->stok_direservasi;
        $service = app(CrossDockService::class);

        $crossDock = $service->tandai([
            'penerimaan_barang_detail_id' => $detail->id,
            'pesanan_penjualan_detail_id' => $pesanan->details->first()->id,
            'jumlah' => 3,
        ], $user);

        $service->batalkan($crossDock);

        $this->assertSame(CrossDock::DIBATALKAN, $crossDock->fresh()->status);
        $this->assertEqualsWithDelta($sebelum, (float) $stok->fresh()->stok_direservasi, 0.000001);
    }

    public function test_posting_the_delivery_consumes_the_mark_and_releases_its_reservation(): void
    {
        $stok = $this->stok();
        $user = $this->operator((int) $stok->gudang_id);
        $detail = $this->penerimaanDetail($stok);
        $pesanan = $this->pesananTerbuka($stok, $user);

        $sebelum = (float) $stok->fresh()->stok_direservasi;
        $penjualan = app(PenjualanService::class);

        $crossDock = app(CrossDockService::class)->tandai([
            'penerimaan_barang_detail_id' => $detail->id,
            'pesanan_penjualan_detail_id' => $pesanan->details->first()->id,
            'jumlah' => 3,
        ], $user);

        $penjualan->postingSuratJalan(
            $penjualan->buatSuratJalan($pesanan->fresh('details'), [
                'tanggal' => today()->toDateString(),
                'details' => [['pesanan_penjualan_detail_id' => $pesanan->details->first()->id, 'jumlah' => 3]],
            ], $user),
            $user
        );

        $this->assertSame(CrossDock::DIKIRIM, $crossDock->fresh()->status);
        $this->assertEqualsWithDelta($sebelum, (float) $stok->fresh()->stok_direservasi, 0.000001);
    }

    public function test_a_partial_shipment_keeps_the_rest_of_the_mark_reserved(): void
    {
        $stok = $this->stok(6);
        $user = $this->operator((int) $stok->gudang_id);
        $detail = $this->penerimaanDetail($stok);
        $pesanan = $this->pesananTerbuka($stok, $user, 4);

        $sebelum = (float) $stok->fresh()->stok_direservasi;
        $penjualan = app(PenjualanService::class);

        $crossDock = app(CrossDockService::class)->tandai([
            'penerimaan_barang_detail_id' => $detail->id,
            'pesanan_penjualan_detail_id' => $pesanan->details->first()->id,
            'jumlah' => 4,
        ], $user);

        $penjualan->postingSuratJalan(
            $penjualan->buatSuratJalan($pesanan->fresh('details'), [
                'tanggal' => today()->toDateString(),
                'details' => [['pesanan_penjualan_detail_id' => $pesanan->details->first()->id, 'jumlah' => 1]],
            ], $user),
            $user
        );

        $lanjutan = CrossDock::where('pesanan_penjualan_detail_id', $pesanan->details->first()->id)
            ->where('status', CrossDock::DIRESERVASI)
            ->first();

        $this->assertSame(CrossDock::DIKIRIM, $crossDock->fresh()->status);
        $this->assertEqualsWithDelta(1, (float) $crossDock->fresh()->jumlah, 0.000001);
        $this->assertNotNull($lanjutan);
        $this->assertEqualsWithDelta(3, (float) $lanjutan->jumlah, 0.000001);
        $this->assertEqualsWithDelta($sebelum + 3, (float) $stok->fresh()->stok_direservasi, 0.000001);
    }

    public function test_the_cross_dock_page_belongs_to_the_warehouse_only(): void
    {
        $this->actingAs($this->operator())->get(route('cross-dock.index'))->assertOk();

        $this->actingAs(User::factory()->create(['type' => User::ROLE_FINANCE, 'is_active' => true]))
            ->get(route('cross-dock.index'))
            ->assertForbidden();
    }

    public function test_a_bom_refuses_a_duplicate_component_and_self_reference(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_PRODUCTION, 'is_active' => true]);
        $produk = Bahan::firstOrFail();
        $komponen = Bahan::whereKeyNot($produk->id)->firstOrFail();
        $service = app(BomService::class);

        try {
            $service->buat([
                'kode' => 'BOM-DUP-' . random_int(100, 999),
                'nama' => 'BOM duplikat',
                'bahan_id' => $produk->id,
                'details' => [
                    ['bahan_id' => $komponen->id, 'jumlah' => 1],
                    ['bahan_id' => $komponen->id, 'jumlah' => 2],
                ],
            ], $user);
            $this->fail('Komponen ganda seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sekali', $e->getMessage());
        }

        $this->expectException(RuntimeException::class);

        $service->buat([
            'kode' => 'BOM-SELF-' . random_int(100, 999),
            'nama' => 'BOM dirinya sendiri',
            'bahan_id' => $produk->id,
            'details' => [['bahan_id' => $produk->id, 'jumlah' => 1]],
        ], $user);
    }

    public function test_only_one_bom_can_be_active_for_a_material(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_PRODUCTION, 'is_active' => true]);
        $produk = Bahan::firstOrFail();
        Bom::where('bahan_id', $produk->id)->update(['status' => Bom::NONAKTIF]);
        $komponen = Bahan::whereKeyNot($produk->id)->firstOrFail();
        $service = app(BomService::class);

        $pertama = $service->buat([
            'kode' => 'BOM-A-' . random_int(100, 999),
            'nama' => 'BOM pertama',
            'bahan_id' => $produk->id,
            'versi' => 'A' . random_int(10, 99),
            'details' => [['bahan_id' => $komponen->id, 'jumlah' => 1]],
        ], $user);

        $kedua = [
            'kode' => 'BOM-B-' . random_int(100, 999),
            'nama' => 'BOM kedua',
            'bahan_id' => $produk->id,
            'versi' => 'B' . random_int(10, 99),
            'details' => [['bahan_id' => $komponen->id, 'jumlah' => 2]],
        ];

        try {
            $service->buat($kedua, $user);
            $this->fail('Membuat BOM kedua untuk bahan yang sudah punya BOM aktif seharusnya ditolak.');
        } catch (RuntimeException) {
        }

        $this->assertSame(
            1,
            Bom::aktif()->where('bahan_id', $produk->id)->count(),
            'Satu bahan hanya boleh punya satu BOM aktif.'
        );

        $service->ubahStatus($pertama, Bom::NONAKTIF);
        $penerus = $service->buat($kedua, $user);

        $this->assertTrue($penerus->isAktif());

        $this->expectException(RuntimeException::class);
        $service->ubahStatus($pertama, Bom::AKTIF);
    }

    public function test_cross_dock_suggestions_never_leak_a_warehouse_the_operator_cannot_reach(): void
    {
        $stok = $this->stok();
        $lain = Gudang::where('jenis', Gudang::NORMAL)->whereKeyNot($stok->gudang_id)->firstOrFail();
        $operator = $this->operator($lain->id);

        $saran = app(CrossDockService::class)->saran(null, $operator->accessibleGudangIds('receive'));

        $this->assertTrue(
            $saran->every(fn ($baris) => (int) $baris['gudang_id'] === (int) $lain->id),
            'Saran cross dock hanya boleh memuat gudang yang boleh diakses operator.'
        );

        $tanpaGudang = User::factory()->create(['type' => User::ROLE_PRODUCTION, 'is_active' => true]);

        $this->assertTrue(
            app(CrossDockService::class)->saran(null, $tanpaGudang->accessibleGudangIds('receive'))->isEmpty(),
            'Operator tanpa pembagian gudang tidak boleh menerima saran apa pun.'
        );
    }

    public function test_usage_variance_compares_actual_consumption_against_the_standard(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_PRODUCTION, 'is_active' => true]);
        $stok = $this->stok(6);
        $bahan = Bahan::findOrFail($stok->bahan_id);
        $produk = Bahan::whereKeyNot($bahan->id)->firstOrFail();

        Bom::where('bahan_id', $produk->id)->update(['status' => Bom::NONAKTIF]);

        app(BomService::class)->buat([
            'kode' => 'BOM-VAR-' . random_int(1000, 9999),
            'nama' => 'BOM varians',
            'bahan_id' => $produk->id,
            'versi' => 'V' . random_int(100, 999),
            'jumlah_hasil' => 1,
            'details' => [['bahan_id' => $bahan->id, 'jumlah' => 1]],
        ], $user);

        $wo = $this->workOrder($produk, $stok, $user, 2);
        $this->npk($wo, $stok, $user, 3);

        $varians = app(BomService::class)->varians($wo->fresh());
        $baris = collect($varians['baris'])->firstWhere('bahan_id', (int) $bahan->id);

        $this->assertNotNull($varians['bom']);
        $this->assertEqualsWithDelta(2, $baris['standar'], 0.000001);
        $this->assertEqualsWithDelta(3, $baris['aktual'], 0.000001);
        $this->assertSame('BOROS', $baris['status']);
        $this->assertGreaterThan(0, $varians['total']['selisih_nilai']);
    }

    public function test_a_work_order_without_a_bom_reports_actual_usage_only(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_PRODUCTION, 'is_active' => true]);
        $stok = $this->stok(6);
        $produk = Bahan::whereKeyNot($stok->bahan_id)->firstOrFail();

        Bom::where('bahan_id', $produk->id)->update(['status' => Bom::NONAKTIF]);

        $wo = $this->workOrder($produk, $stok, $user, 2);
        $this->npk($wo, $stok, $user, 2);

        $varians = app(BomService::class)->varians($wo->fresh());

        $this->assertNull($varians['bom']);
        $this->assertCount(1, $varians['baris']);
        $this->assertSame('DI LUAR BOM', $varians['baris']->first()['status']);
    }

    public function test_the_cross_dock_routes_work_end_to_end_over_http(): void
    {
        $stok = $this->stok();
        $user = $this->operator((int) $stok->gudang_id);
        $detail = $this->penerimaanDetail($stok);
        $pesanan = $this->pesananTerbuka($stok, $user);

        $this->actingAs($user)->post(route('cross-dock.store'), [
            'penerimaan_barang_detail_id' => $detail->id,
            'pesanan_penjualan_detail_id' => $pesanan->details->first()->id,
            'jumlah' => 3,
        ])->assertRedirect();

        $crossDock = CrossDock::where('pesanan_penjualan_detail_id', $pesanan->details->first()->id)->firstOrFail();
        $this->assertSame(CrossDock::DIRESERVASI, $crossDock->status);

        $this->actingAs($user)->post(route('cross-dock.batalkan', $crossDock))->assertRedirect();
        $this->assertSame(CrossDock::DIBATALKAN, $crossDock->fresh()->status);

        $this->actingAs(User::factory()->create(['type' => User::ROLE_FINANCE, 'is_active' => true]))
            ->post(route('cross-dock.store'), [
                'penerimaan_barang_detail_id' => $detail->id,
                'pesanan_penjualan_detail_id' => $pesanan->details->first()->id,
                'jumlah' => 1,
            ])->assertForbidden();
    }

    public function test_the_bom_routes_work_end_to_end_over_http(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_PRODUCTION, 'is_active' => true]);
        $produk = Bahan::firstOrFail();
        $komponen = Bahan::whereKeyNot($produk->id)->firstOrFail();
        $kode = 'BOM-HTTP-' . random_int(1000, 9999);

        Bom::where('bahan_id', $produk->id)->update(['status' => Bom::NONAKTIF]);

        $this->actingAs($user)->post(route('bom.store'), [
            'kode' => $kode,
            'nama' => 'BOM lewat HTTP',
            'bahan_id' => $produk->id,
            'versi' => 'H' . random_int(100, 999),
            'jumlah_hasil' => 1,
            'details' => [['bahan_id' => $komponen->id, 'jumlah' => 2]],
        ])->assertRedirect(route('bom.index'));

        $bom = Bom::where('kode', $kode)->firstOrFail();
        $this->assertTrue($bom->isAktif());

        $this->actingAs($user)->post(route('bom.status', $bom), ['status' => 'TIDAK_DIKENAL'])
            ->assertSessionHasErrors('status');

        $this->actingAs($user)->post(route('bom.status', $bom), ['status' => Bom::NONAKTIF])->assertRedirect();
        $this->assertFalse($bom->fresh()->isAktif());

        $this->actingAs($user)->delete(route('bom.destroy', $bom))->assertRedirect(route('bom.index'));
        $this->assertNull(Bom::find($bom->id));

        $this->actingAs(User::factory()->create(['type' => User::ROLE_WAREHOUSE, 'is_active' => true]))
            ->post(route('bom.store'), [
                'kode' => 'BOM-TOLAK-' . random_int(1000, 9999),
                'nama' => 'Ditolak',
                'bahan_id' => $produk->id,
                'details' => [['bahan_id' => $komponen->id, 'jumlah' => 1]],
            ])->assertForbidden();
    }

    private function workOrder(Bahan $produk, StokGudang $stok, User $user, float $jumlah): DataPesanan
    {
        $pelanggan = Pelanggan::create([
            'kode' => 'CUST-BOM-' . random_int(1000, 9999),
            'nama' => 'PT Uji BOM',
            'termin_hari' => 30,
            'is_active' => true,
        ]);

        $pesanan = app(PenjualanService::class)->buatPesanan([
            'tanggal' => today()->toDateString(),
            'pelanggan_id' => $pelanggan->id,
            'gudang_id' => $stok->gudang_id,
            'is_ppn' => false,
            'details' => [['bahan_id' => $produk->id, 'jumlah' => $jumlah, 'harga_satuan' => 120000]],
        ], $user);

        $service = app(DataPesananService::class);

        return $service->rilis($service->buat($pesanan->details->first(), [
            'tanggal' => today()->toDateString(),
            'gudang_id' => $stok->gudang_id,
            'jumlah_rencana' => $jumlah,
        ], $user));
    }

    private function npk(DataPesanan $wo, StokGudang $stok, User $user, float $jumlah): void
    {
        $bahan = Bahan::findOrFail($stok->bahan_id);

        $npk = PemakaianBarang::create([
            'kode' => app(DocumentNumberService::class)->internal('NPK', 'TST'),
            'kode_datapesanan' => $wo->nomor,
            'data_pesanan_id' => $wo->id,
            'tanggal' => today()->toDateString(),
            'id_barang' => $bahan->id,
            'id_gudang_asal' => $stok->gudang_id,
            'jumlah' => $jumlah,
            'jumlah_stok' => $bahan->toStockQuantity($jumlah),
            'satuan_transaksi' => $bahan->satuan,
            'id_user' => $user->id,
            'status' => PemakaianBarang::POSTED,
        ]);

        app(WmsAccountingService::class)->consumeStock($npk);

        app(StokGudangService::class)->keluar(
            (int) $stok->gudang_id,
            (int) $bahan->id,
            (float) $npk->jumlah_stok,
            (float) $npk->fresh()->harga_satuan,
            'PENGELUARAN',
            'NPK',
            $npk->id,
            $npk->kode
        );

        app(WmsAccountingService::class)->postNpk($npk->fresh());
    }
}
