<?php

namespace Tests\Feature;

use App\Models\BaganAkun;
use App\Models\FakturPenjualan;
use App\Models\Gudang;
use App\Models\Jurnal;
use App\Models\JurnalDetail;
use App\Models\Pelanggan;
use App\Models\PesananPenjualan;
use App\Models\StokGudang;
use App\Models\SuratJalan;
use App\Models\User;
use App\Services\FakturPenjualanService;
use App\Services\PenjualanService;
use App\Services\RekonsiliasiGudangService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PenjualanDanPiutangTest extends TestCase
{
    use DatabaseTransactions;

    private function pelanggan(): Pelanggan
    {
        return Pelanggan::create([
            'kode' => 'CUST-' . random_int(1000, 9999),
            'nama' => 'PT Pembeli Uji',
            'termin_hari' => 30,
            'is_active' => true,
        ]);
    }

    private function stokTersedia(float $minimal = 3): StokGudang
    {
        return StokGudang::whereRaw('stok_tersedia - stok_direservasi >= ?', [$minimal])
            ->whereHas('gudang', fn ($query) => $query->where('jenis', Gudang::NORMAL))
            ->firstOrFail();
    }

    private function buatPesanan(User $user, ?StokGudang $stok = null, float $jumlah = 2, float $harga = 50000): PesananPenjualan
    {
        $stok ??= $this->stokTersedia();

        return app(PenjualanService::class)->buatPesanan([
            'tanggal' => today()->toDateString(),
            'pelanggan_id' => $this->pelanggan()->id,
            'gudang_id' => $stok->gudang_id,
            'is_ppn' => true,
            'tarif_ppn' => 11,
            'details' => [
                ['bahan_id' => $stok->bahan_id, 'jumlah' => $jumlah, 'harga_satuan' => $harga],
            ],
        ], $user);
    }

    private function suratJalanTerposting(User $gudangUser, PesananPenjualan $pesanan): SuratJalan
    {
        $service = app(PenjualanService::class);

        $suratJalan = $service->buatSuratJalan($pesanan, [
            'tanggal' => today()->toDateString(),
            'details' => [
                ['pesanan_penjualan_detail_id' => $pesanan->details->first()->id, 'jumlah' => (float) $pesanan->details->first()->jumlah],
            ],
        ], $gudangUser);

        return $service->postingSuratJalan($suratJalan, $gudangUser);
    }

    public function test_sales_order_totals_include_output_vat(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_PURCHASING]);
        $pesanan = $this->buatPesanan($user);

        $this->assertEqualsWithDelta(100000, (float) $pesanan->total_dpp, 0.01);
        $this->assertEqualsWithDelta(11000, (float) $pesanan->total_ppn, 0.01);
        $this->assertEqualsWithDelta(111000, (float) $pesanan->grand_total, 0.01);
        $this->assertSame(PesananPenjualan::OPEN, $pesanan->status);
    }

    public function test_posting_a_delivery_consumes_fifo_stock_and_books_cost_of_goods_sold(): void
    {
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);
        $gudangUser = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);

        $stok = $this->stokTersedia();
        $stokAwal = (float) $stok->stok_tersedia;

        $pesanan = $this->buatPesanan($purchasing, $stok);
        $suratJalan = $this->suratJalanTerposting($gudangUser, $pesanan);

        $this->assertSame(SuratJalan::POSTED, $suratJalan->status);
        $this->assertGreaterThan(0, (float) $suratJalan->total_hpp);
        $this->assertEqualsWithDelta($stokAwal - 2, (float) $stok->fresh()->stok_tersedia, 0.000001);

        $jurnal = Jurnal::where('sumber_transaksi', 'SURAT_JALAN')->where('reff_id', $suratJalan->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $jurnal->total_debit, (float) $jurnal->total_kredit, 0.01);

        $baris = JurnalDetail::where('jurnal_id', $jurnal->id)->get();
        $hpp = BaganAkun::where('kode_akun', '5401')->firstOrFail();

        $this->assertTrue(
            $baris->contains(fn ($d) => (int) $d->coa_id === (int) $hpp->id && (float) $d->debit > 0),
            'Surat jalan harus mendebit beban pokok penjualan.'
        );
        $this->assertTrue(
            $baris->every(fn ($d) => (int) $d->gudang_id === (int) $suratJalan->gudang_id),
            'Jurnal surat jalan harus membawa dimensi gudang pengirim.'
        );
    }

    public function test_the_delivery_draws_down_the_sales_order_and_closes_it_when_complete(): void
    {
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);
        $gudangUser = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);

        $pesanan = $this->buatPesanan($purchasing);
        $this->suratJalanTerposting($gudangUser, $pesanan);

        $pesanan = $pesanan->fresh('details');
        $this->assertEqualsWithDelta(2, (float) $pesanan->details->first()->jumlah_terkirim, 0.000001);
        $this->assertSame(PesananPenjualan::CLOSED, $pesanan->status);
    }

    public function test_invoicing_a_delivery_creates_receivable_and_output_vat(): void
    {
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);
        $gudangUser = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $akuntan = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);

        $pesanan = $this->buatPesanan($purchasing);
        $suratJalan = $this->suratJalanTerposting($gudangUser, $pesanan);

        $service = app(FakturPenjualanService::class);
        $faktur = $service->buatDariSuratJalan($suratJalan, [
            'tanggal' => today()->toDateString(),
            'no_faktur_pajak' => '010.000-26.00000001',
        ], $akuntan);

        $this->assertSame(FakturPenjualan::DRAFT, $faktur->status);

        $faktur = $service->posting($faktur, $akuntan);

        $this->assertSame(FakturPenjualan::POSTED, $faktur->status);
        $this->assertEqualsWithDelta(111000, (float) $faktur->grand_total, 0.01);
        $this->assertEqualsWithDelta(111000, (float) $faktur->sisa_tagihan, 0.01);

        $jurnal = Jurnal::where('sumber_transaksi', 'FAKTUR_PENJUALAN')->where('reff_id', $faktur->id)->firstOrFail();
        $baris = JurnalDetail::where('jurnal_id', $jurnal->id)->get();

        $piutang = BaganAkun::where('kode_akun', '1201')->firstOrFail();
        $penjualan = BaganAkun::where('kode_akun', '4101')->firstOrFail();
        $ppnKeluaran = BaganAkun::where('kode_akun', '2109')->firstOrFail();

        $this->assertEqualsWithDelta(111000, (float) $baris->firstWhere('coa_id', $piutang->id)->debit, 0.01);
        $this->assertEqualsWithDelta(100000, (float) $baris->firstWhere('coa_id', $penjualan->id)->kredit, 0.01);
        $this->assertEqualsWithDelta(11000, (float) $baris->firstWhere('coa_id', $ppnKeluaran->id)->kredit, 0.01);
        $this->assertEqualsWithDelta((float) $jurnal->total_debit, (float) $jurnal->total_kredit, 0.01);
    }

    public function test_a_vat_invoice_cannot_be_posted_without_its_tax_serial_number(): void
    {
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);
        $gudangUser = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $akuntan = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);

        $pesanan = $this->buatPesanan($purchasing);
        $suratJalan = $this->suratJalanTerposting($gudangUser, $pesanan);

        $service = app(FakturPenjualanService::class);
        $faktur = $service->buatDariSuratJalan($suratJalan, ['tanggal' => today()->toDateString()], $akuntan);

        $this->expectException(\RuntimeException::class);
        $service->posting($faktur, $akuntan);
    }

    public function test_a_delivery_line_cannot_be_invoiced_twice(): void
    {
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);
        $gudangUser = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $akuntan = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);

        $pesanan = $this->buatPesanan($purchasing);
        $suratJalan = $this->suratJalanTerposting($gudangUser, $pesanan);

        $service = app(FakturPenjualanService::class);
        $service->buatDariSuratJalan($suratJalan, ['tanggal' => today()->toDateString()], $akuntan);

        $this->expectException(\RuntimeException::class);
        $service->buatDariSuratJalan($suratJalan->fresh('details'), ['tanggal' => today()->toDateString()], $akuntan);
    }

    public function test_receiving_payment_reduces_the_receivable_and_moves_the_status(): void
    {
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);
        $gudangUser = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $akuntan = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);

        $pesanan = $this->buatPesanan($purchasing);
        $suratJalan = $this->suratJalanTerposting($gudangUser, $pesanan);

        $service = app(FakturPenjualanService::class);
        $faktur = $service->posting(
            $service->buatDariSuratJalan($suratJalan, [
                'tanggal' => today()->toDateString(),
                'no_faktur_pajak' => '010.000-26.00000002',
            ], $akuntan),
            $akuntan
        );

        $kas = BaganAkun::where('is_cash_bank', true)->firstOrFail();

        $service->terimaPembayaran($faktur, [
            'tanggal' => today()->toDateString(),
            'coa_kas_bank_id' => $kas->id,
            'jumlah' => 30000,
        ], $finance);

        $faktur = $faktur->fresh();
        $this->assertSame(FakturPenjualan::PARTIALLY_PAID, $faktur->status);
        $this->assertEqualsWithDelta(81000, (float) $faktur->sisa_tagihan, 0.01);

        $service->terimaPembayaran($faktur, [
            'tanggal' => today()->toDateString(),
            'coa_kas_bank_id' => $kas->id,
            'jumlah' => 81000,
        ], $finance);

        $faktur = $faktur->fresh();
        $this->assertSame(FakturPenjualan::PAID, $faktur->status);
        $this->assertEqualsWithDelta(0, (float) $faktur->sisa_tagihan, 0.01);
    }

    public function test_payment_beyond_the_outstanding_balance_becomes_a_customer_advance(): void
    {
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);
        $gudangUser = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $akuntan = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);

        $pesanan = $this->buatPesanan($purchasing);
        $suratJalan = $this->suratJalanTerposting($gudangUser, $pesanan);

        $service = app(FakturPenjualanService::class);
        $faktur = $service->posting(
            $service->buatDariSuratJalan($suratJalan, [
                'tanggal' => today()->toDateString(),
                'no_faktur_pajak' => '010.000-26.00000003',
            ], $akuntan),
            $akuntan
        );

        $kas = BaganAkun::where('is_cash_bank', true)->firstOrFail();
        $sisa = round((float) $faktur->sisa_tagihan, 2);

        $pembayaran = $service->terimaPembayaran($faktur, [
            'tanggal' => today()->toDateString(),
            'coa_kas_bank_id' => $kas->id,
            'jumlah' => $sisa + 125000,
        ], $finance);

        $this->assertEqualsWithDelta($sisa, (float) $pembayaran->jumlah, 0.01,
            'Faktur hanya boleh tertutup sebesar sisa tagihannya, berapa pun uang yang masuk.');
        $this->assertEqualsWithDelta(0.0, (float) $faktur->fresh()->sisa_tagihan, 0.01);

        $this->assertEqualsWithDelta(
            125000,
            \App\Models\UangMukaPelanggan::saldoPelanggan((int) $faktur->pelanggan_id),
            0.01,
            'Sejak 2026-10-01 kelebihan bayar diparkir sebagai uang muka, bukan ditolak.'
        );
    }

    public function test_the_whole_sales_flow_works_through_http(): void
    {
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $stok = $this->stokTersedia();
        $pelanggan = $this->pelanggan();

        $this->actingAs($admin)->get(route('pesanan-penjualan.create'))->assertOk();

        $this->actingAs($admin)->post(route('pesanan-penjualan.store'), [
            'tanggal' => today()->toDateString(),
            'pelanggan_id' => $pelanggan->id,
            'gudang_id' => $stok->gudang_id,
            'is_ppn' => 1,
            'tarif_ppn' => 11,
            'details' => [
                ['bahan_id' => $stok->bahan_id, 'jumlah' => 1, 'harga_satuan' => 40000],
            ],
        ])->assertRedirect();

        $pesanan = PesananPenjualan::latest('id')->firstOrFail();
        $this->actingAs($admin)->get(route('pesanan-penjualan.show', $pesanan))->assertOk()->assertSee($pesanan->nomor);

        $this->actingAs($admin)->post(route('pesanan-penjualan.kirim', $pesanan), [
            'tanggal' => today()->toDateString(),
            'details' => [
                ['pesanan_penjualan_detail_id' => $pesanan->details->first()->id, 'jumlah' => 1],
            ],
        ])->assertRedirect();

        $suratJalan = SuratJalan::latest('id')->firstOrFail();
        $this->actingAs($admin)->post(route('surat-jalan.post', $suratJalan))->assertRedirect();
        $this->assertSame(SuratJalan::POSTED, $suratJalan->fresh()->status);

        $this->actingAs($admin)->post(route('surat-jalan.faktur', $suratJalan), [
            'tanggal' => today()->toDateString(),
            'no_faktur_pajak' => '010.000-26.00000009',
        ])->assertRedirect();

        $faktur = FakturPenjualan::latest('id')->firstOrFail();
        $this->actingAs($admin)->post(route('faktur-penjualan.post', $faktur))->assertRedirect();
        $this->assertSame(FakturPenjualan::POSTED, $faktur->fresh()->status);

        $kas = BaganAkun::where('is_cash_bank', true)->firstOrFail();
        $this->actingAs($admin)->post(route('faktur-penjualan.bayar', $faktur), [
            'tanggal' => today()->toDateString(),
            'coa_kas_bank_id' => $kas->id,
            'jumlah' => (float) $faktur->fresh()->sisa_tagihan,
        ])->assertRedirect();

        $this->assertSame(FakturPenjualan::PAID, $faktur->fresh()->status);

        $this->actingAs($admin)->get(route('faktur-penjualan.index'))->assertOk();
        $this->actingAs($admin)->get(route('surat-jalan.index'))->assertOk();
        $this->actingAs($admin)->get(route('pelanggan.index'))->assertOk();
    }

    public function test_a_sales_return_puts_stock_back_and_reduces_the_invoice(): void
    {
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $akuntan = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);

        $stok = $this->stokTersedia();
        $pesanan = $this->buatPesanan($admin, $stok);
        $suratJalan = $this->suratJalanTerposting($admin, $pesanan);

        $service = app(FakturPenjualanService::class);
        $faktur = $service->posting(
            $service->buatDariSuratJalan($suratJalan, [
                'tanggal' => today()->toDateString(),
                'no_faktur_pajak' => '010.000-26.00000004',
            ], $akuntan),
            $akuntan
        );

        $stokSebelumRetur = (float) $stok->fresh()->stok_tersedia;

        $this->actingAs($akuntan)->post(route('surat-jalan.retur', $suratJalan), [
            'tanggal' => today()->toDateString(),
            'alasan' => 'Barang tidak sesuai spesifikasi pelanggan.',
            'details' => [
                ['surat_jalan_detail_id' => $suratJalan->details->first()->id, 'jumlah' => 1],
            ],
        ])->assertRedirect();

        $this->assertEqualsWithDelta($stokSebelumRetur + 1, (float) $stok->fresh()->stok_tersedia, 0.000001);

        $faktur = $faktur->fresh();
        $this->assertEqualsWithDelta(55500, (float) $faktur->grand_total, 0.01);

        $retur = \App\Models\ReturPenjualan::latest('id')->firstOrFail();
        $jurnal = Jurnal::where('sumber_transaksi', 'RETUR_PENJUALAN')->where('reff_id', $retur->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $jurnal->total_debit, (float) $jurnal->total_kredit, 0.01);
    }

    public function test_a_return_cannot_exceed_what_was_delivered(): void
    {
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);

        $pesanan = $this->buatPesanan($admin);
        $suratJalan = $this->suratJalanTerposting($admin, $pesanan);

        $this->expectException(\RuntimeException::class);

        app(\App\Services\ReturPenjualanService::class)->buat($suratJalan, [
            'tanggal' => today()->toDateString(),
            'alasan' => 'Percobaan retur berlebih untuk pengujian.',
            'details' => [
                ['surat_jalan_detail_id' => $suratJalan->details->first()->id, 'jumlah' => 99],
            ],
        ], $admin);
    }

    public function test_sales_journals_keep_the_warehouse_inventory_value_reconciled(): void
    {
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);

        $pesanan = $this->buatPesanan($admin);
        $this->suratJalanTerposting($admin, $pesanan);

        foreach (app(RekonsiliasiGudangService::class)->nilaiPerGudang() as $baris) {
            $this->assertEqualsWithDelta(
                0.0,
                $baris['selisih'],
                0.01,
                "Penjualan membuat nilai persediaan gudang {$baris['gudang']} tidak lagi cocok dengan buku besar."
            );
        }
    }

    public function test_sales_module_is_closed_to_roles_without_a_stake(): void
    {
        $produksi = User::factory()->create(['type' => User::ROLE_PRODUCTION]);

        $this->actingAs($produksi)->get(route('pelanggan.index'))->assertForbidden();
        $this->actingAs($produksi)->get(route('faktur-penjualan.index'))->assertForbidden();
    }
}
