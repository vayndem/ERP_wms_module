<?php

namespace Tests\Feature;

use App\Models\FakturPembelian;
use App\Models\PenerimaanBarang;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class NsfpFakturPembelianServerTest extends TestCase
{
    use DatabaseTransactions;

    private function lpb(): PenerimaanBarang
    {
        return PenerimaanBarang::where('document_type', 'GOODS')
            ->whereDoesntHave('invoiceReceipts')
            ->whereNull('no_invoice')
            ->where('status', PenerimaanBarang::POSTED)
            ->with('pembelian')
            ->firstOrFail();
    }

    private function payload(PenerimaanBarang $lpb, string $noInvoice, array $ubah = []): array
    {
        return array_merge([
            'no_invoice' => $noInvoice,
            'lpb_ids' => [$lpb->id],
            'kode_supplier' => $lpb->pembelian->supplier_id,
            'tanggal' => today()->toDateString(),
            'is_ppn' => 0,
            'no_faktur_pajak' => '04002600000601001',
            'tanggal_faktur_pajak' => today()->toDateString(),
        ], $ubah);
    }

    public function test_a_non_ppn_purchase_invoice_stores_no_nsfp_and_no_tax_invoice_date_over_http(): void
    {
        $akuntan = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $lpb = $this->lpb();

        $respons = $this->actingAs($akuntan)
            ->postJson(route('faktur-pembelian.store'), $this->payload($lpb, 'NSFP-SERVER-1'))
            ->assertCreated();

        $faktur = FakturPembelian::findOrFail($respons->json('data.id'));
        $this->assertSame('NON_PPN', $faktur->jenis_pajak);
        $this->assertNull($faktur->no_faktur_pajak);
        $this->assertNull($faktur->tanggal_faktur_pajak);
    }

    public function test_a_ppn_purchase_invoice_keeps_its_nsfp_and_date(): void
    {
        $akuntan = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $lpb = $this->lpb();

        $respons = $this->actingAs($akuntan)
            ->postJson(route('faktur-pembelian.store'), $this->payload($lpb, 'NSFP-SERVER-2', ['is_ppn' => 1]))
            ->assertCreated();

        $faktur = FakturPembelian::findOrFail($respons->json('data.id'));
        $this->assertSame('PPN', $faktur->jenis_pajak);
        $this->assertSame('04002600000601001', $faktur->no_faktur_pajak);
        $this->assertSame(today()->toDateString(), $faktur->tanggal_faktur_pajak->toDateString());
    }

    public function test_switching_an_invoice_from_ppn_to_non_ppn_clears_the_nsfp_over_http(): void
    {
        $akuntan = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $lpb = $this->lpb();

        $id = $this->actingAs($akuntan)
            ->postJson(route('faktur-pembelian.store'), $this->payload($lpb, 'NSFP-SERVER-3', ['is_ppn' => 1]))
            ->assertCreated()
            ->json('data.id');

        $this->assertNotNull(FakturPembelian::findOrFail($id)->no_faktur_pajak);
        FakturPembelian::whereKey($id)->update(['match_status' => 'PENDING']);

        $this->actingAs($akuntan)
            ->putJson(route('faktur-pembelian.update', $id), $this->payload($lpb, 'NSFP-SERVER-3'))
            ->assertOk();

        $faktur = FakturPembelian::findOrFail($id);
        $this->assertSame('NON_PPN', $faktur->jenis_pajak);
        $this->assertNull($faktur->no_faktur_pajak);
        $this->assertNull($faktur->tanggal_faktur_pajak);
    }
}
