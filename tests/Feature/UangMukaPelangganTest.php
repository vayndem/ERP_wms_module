<?php

namespace Tests\Feature;

use App\Models\AccountingSetting;
use App\Models\BaganAkun;
use App\Models\FakturPenjualan;
use App\Models\Gudang;
use App\Models\JurnalDetail;
use App\Models\Pelanggan;
use App\Models\StokGudang;
use App\Models\UangMukaPelanggan;
use App\Models\User;
use App\Services\AccountingReconciliationService;
use App\Services\FakturPenjualanService;
use App\Services\PenjualanService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UangMukaPelangganTest extends TestCase
{
    use DatabaseTransactions;

    private function fakturTerposting(float $harga = 1_000_000): FakturPenjualan
    {
        $sales = User::where('type', User::ROLE_PURCHASING)->firstOrFail();
        $gudangUser = User::where('type', User::ROLE_WAREHOUSE)->firstOrFail();

        $stok = StokGudang::whereRaw('stok_tersedia - stok_direservasi >= 1')
            ->whereHas('gudang', fn ($query) => $query->where('jenis', Gudang::NORMAL))
            ->firstOrFail();

        $pelanggan = Pelanggan::create([
            'kode' => 'UMP-' . random_int(1000, 9999),
            'nama' => 'PT Uji Uang Muka',
            'termin_hari' => 30,
            'plafon_kredit' => 0,
            'is_active' => true,
        ]);

        $penjualan = app(PenjualanService::class);

        $pesanan = $penjualan->buatPesanan([
            'tanggal' => today()->toDateString(),
            'pelanggan_id' => $pelanggan->id,
            'gudang_id' => $stok->gudang_id,
            'is_ppn' => false,
            'tarif_ppn' => 0,
            'details' => [['bahan_id' => $stok->bahan_id, 'jumlah' => 1, 'harga_satuan' => $harga]],
        ], $sales);

        $suratJalan = $penjualan->buatSuratJalan($pesanan, [
            'tanggal' => today()->toDateString(),
            'details' => [['pesanan_penjualan_detail_id' => $pesanan->details->first()->id, 'jumlah' => 1]],
        ], $gudangUser);

        $penjualan->postingSuratJalan($suratJalan, $gudangUser);

        $faktur = app(FakturPenjualanService::class)->buatDariSuratJalan($suratJalan->fresh(), [
            'tanggal' => today()->toDateString(),
        ], $sales);

        return app(FakturPenjualanService::class)->posting($faktur, $sales);
    }

    private function kasBank(): BaganAkun
    {
        return BaganAkun::where('is_cash_bank', true)->where('is_active', true)->firstOrFail();
    }

    public function test_an_overpayment_is_parked_as_a_customer_advance_instead_of_being_refused(): void
    {
        $finance = User::where('type', User::ROLE_FINANCE)->firstOrFail();
        $faktur = $this->fakturTerposting(1_000_000);
        $sisa = (float) $faktur->sisa_tagihan;

        $pembayaran = app(FakturPenjualanService::class)->terimaPembayaran($faktur, [
            'tanggal' => today()->toDateString(),
            'coa_kas_bank_id' => $this->kasBank()->id,
            'jumlah' => $sisa + 250_000,
        ], $finance);

        $this->assertEqualsWithDelta($sisa, (float) $pembayaran->jumlah, 0.01, 'Hanya sebesar sisa tagihan yang boleh menutup piutang.');
        $this->assertEqualsWithDelta(250_000, (float) $pembayaran->jumlah_uang_muka, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $faktur->fresh()->sisa_tagihan, 0.01);
        $this->assertSame(FakturPenjualan::PAID, $faktur->fresh()->status);

        $uangMuka = UangMukaPelanggan::where('pelanggan_id', $faktur->pelanggan_id)->firstOrFail();
        $this->assertEqualsWithDelta(250_000, (float) $uangMuka->sisa, 0.01);
    }

    public function test_the_overpayment_journal_books_cash_receivable_and_the_advance_liability(): void
    {
        $finance = User::where('type', User::ROLE_FINANCE)->firstOrFail();
        $faktur = $this->fakturTerposting(1_000_000);
        $sisa = (float) $faktur->sisa_tagihan;
        $kas = $this->kasBank();

        $pembayaran = app(FakturPenjualanService::class)->terimaPembayaran($faktur, [
            'tanggal' => today()->toDateString(),
            'coa_kas_bank_id' => $kas->id,
            'jumlah' => $sisa + 300_000,
        ], $finance);

        $baris = JurnalDetail::where('jurnal_id', $pembayaran->journal_id)->get();

        $this->assertEqualsWithDelta($sisa + 300_000, (float) $baris->where('coa_id', $kas->id)->sum('debit'), 0.01,
            'Kas harus menerima seluruh uang yang masuk, bukan hanya bagian yang menutup faktur.');
        $this->assertEqualsWithDelta($sisa,
            (float) $baris->where('coa_id', AccountingSetting::accountId(AccountingSetting::PIUTANG_USAHA))->sum('kredit'), 0.01);
        $this->assertEqualsWithDelta(300_000,
            (float) $baris->where('coa_id', AccountingSetting::accountId(AccountingSetting::UANG_MUKA_PELANGGAN))->sum('kredit'), 0.01,
            'Kelebihan bayar adalah liabilitas ke pelanggan, bukan pendapatan.');
    }

    public function test_an_advance_can_settle_a_later_invoice_and_empties_itself(): void
    {
        $finance = User::where('type', User::ROLE_FINANCE)->firstOrFail();
        $pertama = $this->fakturTerposting(1_000_000);

        app(FakturPenjualanService::class)->terimaPembayaran($pertama, [
            'tanggal' => today()->toDateString(),
            'coa_kas_bank_id' => $this->kasBank()->id,
            'jumlah' => (float) $pertama->sisa_tagihan + 400_000,
        ], $finance);

        $uangMuka = UangMukaPelanggan::where('pelanggan_id', $pertama->pelanggan_id)->firstOrFail();

        $kedua = FakturPenjualan::where('pelanggan_id', $pertama->pelanggan_id)
            ->whereKeyNot($pertama->id)
            ->first();

        if (!$kedua) {
            $kedua = $this->fakturTerposting(300_000);
            $kedua->update(['pelanggan_id' => $pertama->pelanggan_id]);
            $kedua = $kedua->fresh();
        }

        $sisaSebelum = (float) $kedua->sisa_tagihan;
        $dipakai = min(300_000, $sisaSebelum);

        $pembayaran = app(FakturPenjualanService::class)->gunakanUangMuka(
            $kedua,
            $uangMuka,
            $dipakai,
            today()->toDateString(),
            $finance
        );

        $this->assertNull($pembayaran->coa_kas_bank_id, 'Pemakaian uang muka tidak menambah kas apa pun.');
        $this->assertEqualsWithDelta($sisaSebelum - $dipakai, (float) $kedua->fresh()->sisa_tagihan, 0.01);
        $this->assertEqualsWithDelta(400_000 - $dipakai, (float) $uangMuka->fresh()->sisa, 0.01);

        $baris = JurnalDetail::where('jurnal_id', $pembayaran->journal_id)->get();

        $this->assertEqualsWithDelta($dipakai,
            (float) $baris->where('coa_id', AccountingSetting::accountId(AccountingSetting::UANG_MUKA_PELANGGAN))->sum('debit'), 0.01,
            'Memakai uang muka harus mengurangi liabilitasnya.');
    }

    public function test_an_advance_of_another_customer_is_refused(): void
    {
        $finance = User::where('type', User::ROLE_FINANCE)->firstOrFail();
        $faktur = $this->fakturTerposting(1_000_000);

        app(FakturPenjualanService::class)->terimaPembayaran($faktur, [
            'tanggal' => today()->toDateString(),
            'coa_kas_bank_id' => $this->kasBank()->id,
            'jumlah' => (float) $faktur->sisa_tagihan + 100_000,
        ], $finance);

        $uangMuka = UangMukaPelanggan::where('pelanggan_id', $faktur->pelanggan_id)->firstOrFail();
        $fakturLain = $this->fakturTerposting(500_000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('milik pelanggan lain');

        app(FakturPenjualanService::class)->gunakanUangMuka($fakturLain, $uangMuka, 50_000, today()->toDateString(), $finance);
    }

    public function test_the_receivable_invariant_survives_an_overpayment(): void
    {
        $finance = User::where('type', User::ROLE_FINANCE)->firstOrFail();
        $faktur = $this->fakturTerposting(1_000_000);

        app(FakturPenjualanService::class)->terimaPembayaran($faktur, [
            'tanggal' => today()->toDateString(),
            'coa_kas_bank_id' => $this->kasBank()->id,
            'jumlah' => (float) $faktur->sisa_tagihan + 500_000,
        ], $finance);

        $cek = app(AccountingReconciliationService::class)->checks()->firstWhere('key', 'ar');

        $this->assertSame(0, (int) $cek['invalid'],
            'Uang muka adalah liabilitas tersendiri; piutang tidak boleh ikut menjadi negatif karenanya.');
    }

    public function test_the_advance_balance_never_goes_negative_through_http(): void
    {
        $finance = User::where('type', User::ROLE_FINANCE)->firstOrFail();
        $faktur = $this->fakturTerposting(1_000_000);

        app(FakturPenjualanService::class)->terimaPembayaran($faktur, [
            'tanggal' => today()->toDateString(),
            'coa_kas_bank_id' => $this->kasBank()->id,
            'jumlah' => (float) $faktur->sisa_tagihan + 100_000,
        ], $finance);

        $uangMuka = UangMukaPelanggan::where('pelanggan_id', $faktur->pelanggan_id)->firstOrFail();
        $kedua = $this->fakturTerposting(900_000);
        $kedua->update(['pelanggan_id' => $faktur->pelanggan_id]);

        $this->actingAs($finance)
            ->post(route('faktur-penjualan.uang-muka', $kedua), [
                'tanggal' => today()->toDateString(),
                'uang_muka_id' => $uangMuka->id,
                'jumlah' => 500_000,
            ])
            ->assertSessionHasErrors('faktur');

        $this->assertEqualsWithDelta(100_000, (float) $uangMuka->fresh()->sisa, 0.01);
        $this->assertSame(0, DB::table('wms_penerimaan_pembayaran')->where('uang_muka_id', $uangMuka->id)->count());
    }
}
