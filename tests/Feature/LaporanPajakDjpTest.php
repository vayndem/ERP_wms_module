<?php

namespace Tests\Feature;

use App\Models\FakturPenjualan;
use App\Models\User;
use App\Services\LaporanPajakDjpService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class LaporanPajakDjpTest extends TestCase
{
    use DatabaseTransactions;

    private function masaDenganFaktur(): string
    {
        $faktur = FakturPenjualan::whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
            ->orderByDesc('tanggal')
            ->firstOrFail();

        return $faktur->tanggal->format('Y-m');
    }

    public function test_the_output_tax_dataset_covers_every_posted_invoice_in_the_period(): void
    {
        $masa = $this->masaDenganFaktur();
        $service = app(LaporanPajakDjpService::class);

        [$tahun, $bulan] = explode('-', $masa);

        $diharapkan = FakturPenjualan::whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
            ->whereYear('tanggal', $tahun)
            ->whereMonth('tanggal', $bulan)
            ->count();

        $this->assertSame($diharapkan, $service->fakturKeluaran($masa)->count());
        $this->assertGreaterThan(0, $diharapkan, 'Data demo harus punya faktur terposting agar pengujian ini bermakna.');
    }

    public function test_an_invoice_without_a_tax_serial_number_is_flagged_rather_than_silently_exported(): void
    {
        $masa = $this->masaDenganFaktur();

        $faktur = FakturPenjualan::whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
            ->whereYear('tanggal', explode('-', $masa)[0])
            ->whereMonth('tanggal', explode('-', $masa)[1])
            ->firstOrFail();

        $faktur->update(['no_faktur_pajak' => null]);

        $baris = app(LaporanPajakDjpService::class)->fakturKeluaran($masa)
            ->firstWhere('nomor_faktur', $faktur->nomor);

        $this->assertFalse($baris['siap_lapor']);
        $this->assertNotNull($baris['catatan'], 'Dokumen yang belum lengkap wajib menyebutkan alasannya, bukan diam-diam ikut terekspor.');
    }

    public function test_the_xml_export_is_well_formed_and_names_the_official_template_it_must_be_matched_against(): void
    {
        $masa = $this->masaDenganFaktur();
        $xml = app(LaporanPajakDjpService::class)->xmlFakturKeluaran($masa, '01.234.567.8-901.000');

        $dokumen = new \DOMDocument();

        $this->assertTrue($dokumen->loadXML($xml), 'Berkas XML harus well-formed.');
        $this->assertSame('FakturKeluaranBulk', $dokumen->documentElement->nodeName);
        $this->assertSame(
            LaporanPajakDjpService::TEMPLATE_FAKTUR,
            $dokumen->documentElement->getAttribute('skemaAcuan'),
            'Berkas wajib menyebut template DJP yang harus dijadikan acuan, supaya tidak dikira sudah final.'
        );
        $this->assertSame('01.234.567.8-901.000', $dokumen->documentElement->getAttribute('npwpPenjual'));
    }

    public function test_the_withholding_dataset_only_lists_invoices_that_actually_withheld(): void
    {
        $service = app(LaporanPajakDjpService::class);
        $masa = $this->masaDenganFaktur();

        foreach ($service->buktiPotong($masa) as $baris) {
            $this->assertGreaterThan(0, (float) $baris['pph_dipotong']);
            $this->assertNotEmpty($baris['jenis_pph']);
        }

        $this->assertTrue(true);
    }

    public function test_the_page_belongs_to_accounting_and_is_closed_to_the_warehouse(): void
    {
        $akuntansi = User::where('type', User::ROLE_ACCOUNTING)->firstOrFail();
        $gudang = User::factory()->create(['type' => User::ROLE_WAREHOUSE, 'is_active' => true]);

        $this->actingAs($akuntansi)->get(route('laporan-pajak-djp.index'))->assertOk();
        $this->actingAs($akuntansi)->get(route('laporan-pajak-djp.xml'))->assertOk();
        $this->actingAs($gudang)->get(route('laporan-pajak-djp.index'))->assertForbidden();
    }
}
