<?php

namespace Tests\Feature;

use App\Models\BaganAkun;
use App\Models\Bahan;
use App\Models\FakturPembelian;
use App\Models\Gudang;
use App\Models\KategoriBahan;
use App\Models\LayerPersediaan;
use App\Models\PembayaranFaktur;
use App\Models\PenerimaanBarang;
use App\Models\PenerimaanBarangDetail;
use App\Models\PesananPembelian;
use App\Models\ReturPembelian;
use App\Models\StokGudang;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DocumentNumberService;
use App\Services\InventoryReversalService;
use App\Services\StokGudangService;
use App\Services\ThreeWayMatchService;
use App\Services\WmsAccountingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PaymentAndInvoiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_seeded_documents_pass_three_way_matching(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        Auth::login($user);
        $invoice = FakturPembelian::where('status', '!=', FakturPembelian::VOID)->firstOrFail();

        $result = app(ThreeWayMatchService::class)->evaluate($invoice);

        $this->assertSame('MATCHED', $result['status']);
        $this->assertSame([], $result['issues']);
    }

    public function test_invoice_reconciliation_detail_uses_canonical_status_column(): void
    {
        $accounting = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);

        $this->actingAs($accounting)
            ->get(route('reconciliation.show', 'invoice'))
            ->assertOk()
            ->assertSee('Detail Rekonsiliasi')
            ->assertSee('Partially Paid');
    }

    public function test_void_payment_is_excluded_from_active_invoice_payments(): void
    {
        $payment = PembayaranFaktur::where('status', PembayaranFaktur::POSTED)->firstOrFail();
        $invoice = $payment->invoice;
        $activeBefore = $invoice->payments()->count();

        $payment->update(['status' => PembayaranFaktur::VOID]);

        $this->assertSame($activeBefore - 1, $invoice->payments()->count());
        $this->assertSame(PembayaranFaktur::VOID, $payment->fresh()->status);
    }

    public function test_supplier_advance_can_be_generated_then_consumed_by_a_later_payment(): void
    {
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);
        $supplier = Supplier::create([
            'nama' => 'Supplier Uang Muka Test',
            'alamat' => 'Jl. Test No. 1',
            'telp' => '0800000000',
            'pembayaran' => 'Transfer',
        ]);
        $invoiceA = FakturPembelian::create([
            'no_invoice' => 'ADV-TEST-INV-A',
            'kode_supplier' => $supplier->id,
            'tanggal' => today(),
            'grand_total' => 1000000,
            'sisa_tagihan' => 1000000,
            'status' => FakturPembelian::UNPAID,
        ]);
        $invoiceB = FakturPembelian::create([
            'no_invoice' => 'ADV-TEST-INV-B',
            'kode_supplier' => $supplier->id,
            'tanggal' => today(),
            'grand_total' => 500000,
            'sisa_tagihan' => 500000,
            'status' => FakturPembelian::UNPAID,
        ]);
        $kasUtama = BaganAkun::where('kode_akun', '1101')->firstOrFail();
        $uangMukaAccount = BaganAkun::where('kode_akun', '1401')->firstOrFail();
        $numbers = app(DocumentNumberService::class);

        $responseA = $this->actingAs($finance)->postJson(route('pembayaran-faktur.store'), [
            'payment_number' => $numbers->financial('PY'),
            'invoice_lpb_id' => $invoiceA->id,
            'tanggal_pembayaran' => today()->toDateString(),
            'metode_pembayaran' => 'Transfer BCA',
            'coa_kas_bank_id' => $kasUtama->id,
            'jumlah_pembayaran' => 1200000,
            'selisih_bayar' => 200000,
            'jenis_selisih' => 'UANG_MUKA_SUPPLIER',
            'coa_selisih_id' => $uangMukaAccount->id,
        ]);
        $responseA->assertCreated();
        $paymentA = PembayaranFaktur::findOrFail($responseA->json('data.id'));
        $this->assertSame(200000.0, (float) $paymentA->kelebihan_pembayaran);
        $this->assertSame(FakturPembelian::PAID, $invoiceA->fresh()->status);
        $this->assertSame(200000.0, $paymentA->sisaUangMuka());

        $advances = $this->actingAs($finance)
            ->getJson(route('pembayaran-faktur.available-advances', $supplier->id))
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $advances);
        $this->assertSame($paymentA->id, $advances[0]['id']);
        $this->assertSame(200000.0, (float) $advances[0]['sisa']);

        $responseB = $this->actingAs($finance)->postJson(route('pembayaran-faktur.store'), [
            'payment_number' => $numbers->financial('PY'),
            'invoice_lpb_id' => $invoiceB->id,
            'tanggal_pembayaran' => today()->toDateString(),
            'metode_pembayaran' => 'Transfer BCA',
            'coa_kas_bank_id' => $kasUtama->id,
            'jumlah_pembayaran' => 300000,
            'uang_muka_sumber_payment_id' => $paymentA->id,
            'uang_muka_dipakai' => 200000,
        ]);
        $responseB->assertCreated();
        $paymentB = PembayaranFaktur::findOrFail($responseB->json('data.id'));
        $this->assertSame(500000.0, (float) $paymentB->total_transaksi_pengurang_hutang);
        $this->assertSame(FakturPembelian::PAID, $invoiceB->fresh()->status);
        $this->assertSame(0.0, $paymentA->sisaUangMuka());

        $journalB = $paymentB->fresh()->load('invoice');
        $jurnal = \App\Models\Jurnal::with('details')->where('sumber_transaksi', 'PELUNASAN_HUTANG')->where('reff_id', $paymentB->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $jurnal->total_debit, (float) $jurnal->total_kredit, 0.01);
        $this->assertTrue($jurnal->details->contains(fn ($line) => (int) $line->coa_id === $uangMukaAccount->id && (float) $line->kredit === 200000.0));

        $this->actingAs($finance)->deleteJson(route('pembayaran-faktur.destroy', $paymentA->id))
            ->assertStatus(422);

        $this->actingAs($finance)->deleteJson(route('pembayaran-faktur.destroy', $paymentB->id))
            ->assertOk();
        $this->assertSame(PembayaranFaktur::VOID, $paymentB->fresh()->status);

        $this->actingAs($finance)->deleteJson(route('pembayaran-faktur.destroy', $paymentA->id))
            ->assertOk();
        $this->assertSame(PembayaranFaktur::VOID, $paymentA->fresh()->status);
    }

    public function test_supplier_advance_cannot_be_used_across_different_suppliers(): void
    {
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);
        $supplierOne = Supplier::create(['nama' => 'Supplier Satu', 'alamat' => 'Jl. Satu', 'telp' => '0800000001', 'pembayaran' => 'Transfer']);
        $supplierTwo = Supplier::create(['nama' => 'Supplier Dua', 'alamat' => 'Jl. Dua', 'telp' => '0800000002', 'pembayaran' => 'Transfer']);
        $invoiceOne = FakturPembelian::create(['no_invoice' => 'ADV-TEST-X1', 'kode_supplier' => $supplierOne->id, 'tanggal' => today(), 'grand_total' => 1000000, 'sisa_tagihan' => 1000000, 'status' => FakturPembelian::UNPAID]);
        $invoiceTwo = FakturPembelian::create(['no_invoice' => 'ADV-TEST-X2', 'kode_supplier' => $supplierTwo->id, 'tanggal' => today(), 'grand_total' => 500000, 'sisa_tagihan' => 500000, 'status' => FakturPembelian::UNPAID]);
        $kasUtama = BaganAkun::where('kode_akun', '1101')->firstOrFail();
        $uangMukaAccount = BaganAkun::where('kode_akun', '1401')->firstOrFail();
        $numbers = app(DocumentNumberService::class);

        $responseOne = $this->actingAs($finance)->postJson(route('pembayaran-faktur.store'), [
            'payment_number' => $numbers->financial('PY'),
            'invoice_lpb_id' => $invoiceOne->id,
            'tanggal_pembayaran' => today()->toDateString(),
            'metode_pembayaran' => 'Transfer BCA',
            'coa_kas_bank_id' => $kasUtama->id,
            'jumlah_pembayaran' => 1200000,
            'selisih_bayar' => 200000,
            'jenis_selisih' => 'UANG_MUKA_SUPPLIER',
            'coa_selisih_id' => $uangMukaAccount->id,
        ])->assertCreated();
        $paymentOne = PembayaranFaktur::findOrFail($responseOne->json('data.id'));

        $this->actingAs($finance)->postJson(route('pembayaran-faktur.store'), [
            'payment_number' => $numbers->financial('PY'),
            'invoice_lpb_id' => $invoiceTwo->id,
            'tanggal_pembayaran' => today()->toDateString(),
            'metode_pembayaran' => 'Transfer BCA',
            'coa_kas_bank_id' => $kasUtama->id,
            'jumlah_pembayaran' => 300000,
            'uang_muka_sumber_payment_id' => $paymentOne->id,
            'uang_muka_dipakai' => 200000,
        ])->assertStatus(422);
    }

    public function test_pph22_withholding_is_posted_to_its_own_liability_account_on_payment(): void
    {
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);
        $supplier = Supplier::create(['nama' => 'Supplier PPh22', 'alamat' => 'Jl. PPh22', 'telp' => '0800000010', 'pembayaran' => 'Transfer']);
        $invoice = FakturPembelian::create([
            'no_invoice' => 'PPH22-TEST-1',
            'kode_supplier' => $supplier->id,
            'tanggal' => today(),
            'sub_total' => 10000000,
            'jenis_pph' => 'PPH22',
            'dasar_pph' => 10000000,
            'tarif_pph' => 1.5,
            'grand_total' => 10000000,
            'sisa_tagihan' => 10000000,
            'status' => FakturPembelian::UNPAID,
        ]);
        $kasUtama = BaganAkun::where('kode_akun', '1101')->firstOrFail();
        $hutangPph22 = BaganAkun::where('kode_akun', '2105')->firstOrFail();
        $numbers = app(DocumentNumberService::class);

        $response = $this->actingAs($finance)->postJson(route('pembayaran-faktur.store'), [
            'payment_number' => $numbers->financial('PY'),
            'invoice_lpb_id' => $invoice->id,
            'tanggal_pembayaran' => today()->toDateString(),
            'metode_pembayaran' => 'Transfer BCA',
            'coa_kas_bank_id' => $kasUtama->id,
            'jumlah_pembayaran' => 9850000,
            'potongan_pph' => 150000,
        ])->assertCreated();
        $payment = PembayaranFaktur::findOrFail($response->json('data.id'));

        $jurnal = \App\Models\Jurnal::with('details')->where('sumber_transaksi', 'PELUNASAN_HUTANG')->where('reff_id', $payment->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $jurnal->total_debit, (float) $jurnal->total_kredit, 0.01);
        $this->assertTrue($jurnal->details->contains(fn ($line) => (int) $line->coa_id === $hutangPph22->id && (float) $line->kredit === 150000.0));
    }

    public function test_pph4a2_final_withholding_is_posted_to_its_own_liability_account_on_payment(): void
    {
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);
        $supplier = Supplier::create(['nama' => 'Supplier PPh4a2', 'alamat' => 'Jl. PPh4a2', 'telp' => '0800000011', 'pembayaran' => 'Transfer']);
        $invoice = FakturPembelian::create([
            'no_invoice' => 'PPH4A2-TEST-1',
            'kode_supplier' => $supplier->id,
            'tanggal' => today(),
            'sub_total' => 5000000,
            'jenis_pph' => 'PPH4A2',
            'dasar_pph' => 5000000,
            'tarif_pph' => 10,
            'grand_total' => 5000000,
            'sisa_tagihan' => 5000000,
            'status' => FakturPembelian::UNPAID,
        ]);
        $kasUtama = BaganAkun::where('kode_akun', '1101')->firstOrFail();
        $hutangPph4a2 = BaganAkun::where('kode_akun', '2106')->firstOrFail();
        $numbers = app(DocumentNumberService::class);

        $response = $this->actingAs($finance)->postJson(route('pembayaran-faktur.store'), [
            'payment_number' => $numbers->financial('PY'),
            'invoice_lpb_id' => $invoice->id,
            'tanggal_pembayaran' => today()->toDateString(),
            'metode_pembayaran' => 'Transfer BCA',
            'coa_kas_bank_id' => $kasUtama->id,
            'jumlah_pembayaran' => 4500000,
            'potongan_pph' => 500000,
        ])->assertCreated();
        $payment = PembayaranFaktur::findOrFail($response->json('data.id'));

        $jurnal = \App\Models\Jurnal::with('details')->where('sumber_transaksi', 'PELUNASAN_HUTANG')->where('reff_id', $payment->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $jurnal->total_debit, (float) $jurnal->total_kredit, 0.01);
        $this->assertTrue($jurnal->details->contains(fn ($line) => (int) $line->coa_id === $hutangPph4a2->id && (float) $line->kredit === 500000.0));
    }

    public function test_payment_cannot_withhold_pph_when_invoice_has_no_pph_type(): void
    {
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);
        $supplier = Supplier::create(['nama' => 'Supplier Tanpa PPh', 'alamat' => 'Jl. Tanpa PPh', 'telp' => '0800000012', 'pembayaran' => 'Transfer']);
        $invoice = FakturPembelian::create([
            'no_invoice' => 'NOPPH-TEST-1',
            'kode_supplier' => $supplier->id,
            'tanggal' => today(),
            'sub_total' => 2000000,
            'jenis_pph' => null,
            'dasar_pph' => 0,
            'tarif_pph' => 0,
            'grand_total' => 2000000,
            'sisa_tagihan' => 2000000,
            'status' => FakturPembelian::UNPAID,
        ]);
        $kasUtama = BaganAkun::where('kode_akun', '1101')->firstOrFail();
        $numbers = app(DocumentNumberService::class);

        $this->actingAs($finance)->postJson(route('pembayaran-faktur.store'), [
            'payment_number' => $numbers->financial('PY'),
            'invoice_lpb_id' => $invoice->id,
            'tanggal_pembayaran' => today()->toDateString(),
            'metode_pembayaran' => 'Transfer BCA',
            'coa_kas_bank_id' => $kasUtama->id,
            'jumlah_pembayaran' => 1900000,
            'potongan_pph' => 100000,
        ])->assertStatus(422)
            ->assertJson(['message' => 'Invoice ini tidak menetapkan jenis PPh, sehingga tidak ada potongan PPh yang dapat dicatat.']);
    }

    public function test_invoice_requires_accounting_manager_approval_before_posting_or_payment(): void
    {
        $accounting = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $manager = User::factory()->create(['type' => User::ROLE_ACCOUNTING_MANAGER]);
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);
        $lpb = PenerimaanBarang::where('document_type', 'GOODS')
            ->whereDoesntHave('invoiceReceipts')
            ->whereNull('no_invoice')
            ->where('status', PenerimaanBarang::POSTED)
            ->with('pembelian')
            ->firstOrFail();

        $storeResponse = $this->actingAs($accounting)->postJson(route('faktur-pembelian.store'), [
            'no_invoice' => 'MAKER-CHECKER-TEST-1',
            'lpb_ids' => [$lpb->id],
            'kode_supplier' => $lpb->pembelian->supplier_id,
            'tanggal' => today()->toDateString(),
            'is_ppn' => 0,
        ])->assertCreated();
        $invoice = FakturPembelian::findOrFail($storeResponse->json('data.id'));

        $this->assertSame(FakturPembelian::PENDING_APPROVAL, $invoice->status);
        $this->assertDatabaseMissing('wms_jurnal', ['sumber_transaksi' => 'INVOICE_SUPPLIER', 'reff_id' => $invoice->id]);

        $this->actingAs($accounting)
            ->get(route('reconciliation.index'))
            ->assertOk()
            ->assertDontSee('TIDAK VALID');

        $kasUtama = BaganAkun::where('kode_akun', '1101')->firstOrFail();
        $numbers = app(DocumentNumberService::class);
        $this->actingAs($finance)->postJson(route('pembayaran-faktur.store'), [
            'payment_number' => $numbers->financial('PY'),
            'invoice_lpb_id' => $invoice->id,
            'tanggal_pembayaran' => today()->toDateString(),
            'metode_pembayaran' => 'Transfer BCA',
            'coa_kas_bank_id' => $kasUtama->id,
            'jumlah_pembayaran' => 100,
        ])->assertStatus(403);

        $this->actingAs($accounting)->postJson(route('faktur-pembelian.approve', $invoice->id))->assertStatus(403);

        $this->actingAs($manager)->postJson(route('faktur-pembelian.approve', $invoice->id))->assertOk();

        $invoice->refresh();
        $this->assertSame(FakturPembelian::UNPAID, $invoice->status);
        $this->assertSame($manager->id, $invoice->approved_by);
        $this->assertNotNull($invoice->approved_at);
        $jurnal = \App\Models\Jurnal::with('details')->where('sumber_transaksi', 'INVOICE_SUPPLIER')->where('reff_id', $invoice->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $jurnal->total_debit, (float) $jurnal->total_kredit, 0.01);

        $this->actingAs($manager)->postJson(route('faktur-pembelian.approve', $invoice->id))->assertStatus(403);
    }

    public function test_invoice_posts_ppn_impor_as_a_separate_creditable_line(): void
    {
        $accounting = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        Auth::login($accounting);
        $lpb = PenerimaanBarang::where('document_type', 'GOODS')
            ->whereDoesntHave('invoiceReceipts')
            ->whereNull('no_invoice')
            ->where('status', PenerimaanBarang::POSTED)
            ->with(['details', 'pembelian'])
            ->firstOrFail();
        $subTotal = $lpb->details->sum(fn ($d) => (float) $d->jumlah_barang_diterima * (float) $d->harga);

        $invoice = FakturPembelian::create([
            'no_invoice' => 'PPNIMPOR-TEST-1',
            'kode_supplier' => $lpb->pembelian->supplier_id,
            'tanggal' => today(),
            'sub_total' => $subTotal,
            'ppn_impor' => 150000,
            'mata_uang_asing' => 'USD',
            'kurs' => 15800,
            'nilai_asing' => round($subTotal / 15800, 2),
            'grand_total' => $subTotal + 150000,
            'sisa_tagihan' => $subTotal + 150000,
            'status' => FakturPembelian::UNPAID,
        ]);
        $invoice->receipts()->create(['lpb_id' => $lpb->id, 'amount' => $subTotal]);
        $lpb->update(['no_invoice' => $invoice->no_invoice]);

        app(WmsAccountingService::class)->postInvoice($invoice);

        $jurnal = \App\Models\Jurnal::with('details')->where('sumber_transaksi', 'INVOICE_SUPPLIER')->where('reff_id', $invoice->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $jurnal->total_debit, (float) $jurnal->total_kredit, 0.01);
        $ppnImporAccount = BaganAkun::where('kode_akun', '1104')->firstOrFail();
        $this->assertTrue($jurnal->details->contains(fn ($line) => (int) $line->coa_id === $ppnImporAccount->id && abs((float) $line->debit - 150000.0) < 0.01));
    }

    public function test_purchase_return_after_partially_paid_invoice_reduces_invoice_balance(): void
    {
        $warehouse = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);
        $sisaLayer = fn ($d) => (float) (LayerPersediaan::where('source_type', 'LPB_DETAIL')
            ->where('source_id', $d->id)->value('remaining_quantity') ?? 0);

        $pilihan = PenerimaanBarang::where('document_type', 'GOODS')
            ->whereNotNull('no_invoice')
            ->where('status', PenerimaanBarang::POSTED)
            ->get()
            ->map(function ($candidate) use ($sisaLayer) {
                $detail = $candidate->details->first(fn ($d) => $sisaLayer($d) > 0);
                $invoice = FakturPembelian::where('no_invoice', $candidate->no_invoice)->first();

                if (!$detail || !$invoice) {
                    return null;
                }

                $returQty = min(1.0, $sisaLayer($detail));

                return [
                    'lpb' => $candidate,
                    'detail' => $detail,
                    'invoice' => $invoice,
                    'retur_qty' => $returQty,
                    'retur_value' => round($returQty * (float) $detail->harga, 2),
                ];
            })
            ->filter()
            ->sortByDesc(fn ($baris) => (float) $baris['invoice']->sisa_tagihan)
            ->first(fn ($baris) => $baris['retur_qty'] > 0
                && $baris['invoice']->status !== FakturPembelian::VOID);

        $this->assertNotNull(
            $pilihan,
            'Butuh satu LPB bernomor invoice yang masih punya layer tersisa untuk diretur.'
        );

        $lpb = $pilihan['lpb'];
        $detail = $pilihan['detail'];
        $invoice = $pilihan['invoice'];
        $layer = LayerPersediaan::where('source_type', 'LPB_DETAIL')->where('source_id', $detail->id)->firstOrFail();

        $returQty = $pilihan['retur_qty'];
        $returValue = $pilihan['retur_value'];
        $sisaBefore = (float) $invoice->sisa_tagihan;
        $grandBefore = (float) $invoice->grand_total;

        $numbers = app(DocumentNumberService::class);
        $response = $this->actingAs($warehouse)->postJson(route('retur-pembelian.store'), [
            'no_retur' => $numbers->external('RTV'),
            'lpb_id' => $lpb->id,
            'tanggal' => today()->toDateString(),
            'alasan' => 'Retur setelah invoice terbit (integration test).',
            'details' => [['lpb_detail_id' => $detail->id, 'jumlah_retur' => $returQty]],
        ])->assertCreated();
        $retur = ReturPembelian::findOrFail($response->json('data.id'));

        $invoice->refresh();
        $this->assertEqualsWithDelta($grandBefore - $returValue, (float) $invoice->grand_total, 0.01);

        $this->assertEqualsWithDelta(
            max($sisaBefore - $returValue, 0.0),
            (float) $invoice->sisa_tagihan,
            0.01,
            'Retur memotong sisa tagihan sebesar nilainya, dan tidak boleh membuat sisa tagihan menjadi negatif ketika faktur sudah lunas.'
        );

        $this->assertGreaterThanOrEqual(0.0, (float) $invoice->sisa_tagihan);
        $this->assertEqualsWithDelta($returValue, (float) $retur->hutang_reduction, 0.01);
        $this->assertNull($retur->advance_payment_id);

        $jurnal = \App\Models\Jurnal::with('details')->where('sumber_transaksi', 'RETUR_PEMBELIAN')->where('reff_id', $retur->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $jurnal->total_debit, (float) $jurnal->total_kredit, 0.01);
        $hutangAccount = BaganAkun::where('kode_akun', '2101')->firstOrFail();
        $this->assertTrue($jurnal->details->contains(fn ($line) => (int) $line->coa_id === $hutangAccount->id && abs((float) $line->debit - $returValue) < 0.01));
    }

    public function test_purchase_return_after_fully_paid_invoice_creates_supplier_advance(): void
    {
        $warehouse = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);
        $accounting = app(WmsAccountingService::class);
        $numbers = app(DocumentNumberService::class);
        $category = KategoriBahan::where('katnama', 'Bahan Baku Paper')->firstOrFail();
        $gudang = Gudang::where('nama', 'Gudang Utama')->firstOrFail();
        $supplier = Supplier::create(['nama' => 'Supplier Retur Lunas', 'alamat' => 'Jl. Retur', 'telp' => '0800000099', 'pembayaran' => 'Transfer']);

        $material = Bahan::create([
            'nama' => 'Bahan Uji Retur Lunas',
            'kategori' => $category->id,
            'satuan' => 'KG',
            'stok_onhand' => 0,
            'stok_onpurchase' => 0,
            'planning' => 0,
            'stokawal' => 0,
            'pengambilan_stokawal' => 0,
            'tipe_gudang' => $gudang->id,
            'tipe_barang' => $category->id,
        ]);

        $po = PesananPembelian::create([
            'no_po' => $numbers->financial('PO', today()->subDays(10)),
            'tanggal' => today()->subDays(10),
            'supplier_id' => $supplier->id,
            'gudang_id' => $gudang->id,
            'no_order' => '-',
            'untuk_perhatian' => 'Uji Retur Lunas',
            'term' => '30 hari',
            'notes' => 'PO uji retur setelah invoice lunas',
            'total_exclude' => 100000,
            'total_include' => 100000,
            'grand_total' => 100000,
            'status' => PesananPembelian::OPEN,
            'jenis' => 0,
            'kunci' => 1,
        ]);

        $lpbNumber = $numbers->external('LPB', today()->subDays(5));
        $lpb = PenerimaanBarang::create([
            'id_lpb' => $lpbNumber,
            'tanggal' => today()->subDays(5),
            'no_po' => $po->no_po,
            'gudang_id' => $gudang->id,
            'no_sj' => 'SJ-' . $lpbNumber,
            'id_user' => 5,
            'flag' => 0,
            'status' => PenerimaanBarang::POSTED,
            'jenis_lpb' => 1,
            'kunci' => 1,
        ]);
        $detail = PenerimaanBarangDetail::create([
            'id_lpb' => $lpb->id_lpb,
            'id_bahan' => $material->id,
            'id_kategori' => $category->id,
            'jumlah_barang_diterima' => 10,
            'lot_number' => 'LOT-RETUR-LUNAS',
            'harga' => 10000,
            'nilai_awal' => 100000,
            'jumlah_dipakai' => 0,
            'jumlah_tersisa' => 10,
            'flag_dipakai' => 1,
        ]);
        LayerPersediaan::create([
            'bahan_id' => $material->id,
            'gudang_id' => $gudang->id,
            'source_type' => 'LPB_DETAIL',
            'source_id' => $detail->id,
            'transaction_date' => today()->subDays(5),
            'initial_quantity' => 10,
            'remaining_quantity' => 10,
            'unit_cost' => 10000,
        ]);
        app(StokGudangService::class)->masuk($gudang->id, $material->id, 10, 10000, 'LPB', 'LPB', $lpb->id, 'Fixture uji retur lunas');
        $accounting->postLpb($lpb);

        $invoice = FakturPembelian::create([
            'no_invoice' => 'RETUR-LUNAS-TEST-1',
            'kode_supplier' => $supplier->id,
            'tanggal' => today()->subDays(3),
            'sub_total' => 100000,
            'grand_total' => 100000,
            'total_pembayaran' => 100000,
            'sisa_tagihan' => 0,
            'status' => FakturPembelian::PAID,
        ]);
        $invoice->receipts()->create(['lpb_id' => $lpb->id, 'amount' => 100000]);
        $lpb->update(['no_invoice' => $invoice->no_invoice]);
        $accounting->postInvoice($invoice);

        $response = $this->actingAs($warehouse)->postJson(route('retur-pembelian.store'), [
            'no_retur' => $numbers->external('RTV'),
            'lpb_id' => $lpb->id,
            'tanggal' => today()->toDateString(),
            'alasan' => 'Retur setelah invoice lunas (integration test).',
            'details' => [['lpb_detail_id' => $detail->id, 'jumlah_retur' => 2]],
        ])->assertCreated();
        $retur = ReturPembelian::findOrFail($response->json('data.id'));

        $this->assertEqualsWithDelta(0.0, (float) $retur->hutang_reduction, 0.01);
        $this->assertNotNull($retur->advance_payment_id);

        $advance = PembayaranFaktur::findOrFail($retur->advance_payment_id);
        $this->assertSame('UANG_MUKA_SUPPLIER', $advance->jenis_selisih);
        $this->assertEqualsWithDelta(20000.0, (float) $advance->kelebihan_pembayaran, 0.01);
        $this->assertNull($advance->coa_kas_bank_id);

        $jurnal = \App\Models\Jurnal::with('details')->where('sumber_transaksi', 'RETUR_PEMBELIAN')->where('reff_id', $retur->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $jurnal->total_debit, (float) $jurnal->total_kredit, 0.01);
        $uangMukaAccount = BaganAkun::where('kode_akun', '1401')->firstOrFail();
        $this->assertTrue($jurnal->details->contains(fn ($line) => (int) $line->coa_id === $uangMukaAccount->id && abs((float) $line->debit - 20000.0) < 0.01));

        $availableAdvances = $this->actingAs(User::factory()->create(['type' => User::ROLE_FINANCE]))
            ->getJson(route('pembayaran-faktur.available-advances', $supplier->id))
            ->assertOk()
            ->json('data');
        $this->assertTrue(collect($availableAdvances)->contains(fn ($row) => (int) $row['id'] === $advance->id));

        $this->actingAs(User::factory()->create(['type' => User::ROLE_FINANCE]))
            ->deleteJson(route('pembayaran-faktur.destroy', $advance->id))
            ->assertStatus(422);

        app(InventoryReversalService::class)->reverseReturPembelian($retur, 'Koreksi integration test retur pasca invoice lunas');

        $this->assertSame(ReturPembelian::REVERSED, $retur->fresh()->status);
        $this->assertEqualsWithDelta(100000.0, (float) $invoice->fresh()->grand_total, 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $invoice->fresh()->sisa_tagihan, 0.01);
        $this->assertSame(PembayaranFaktur::VOID, $advance->fresh()->status);
        $this->assertEqualsWithDelta(10.0, (float) LayerPersediaan::where('source_type', 'LPB_DETAIL')->where('source_id', $detail->id)->value('remaining_quantity'), 0.000001);
    }

    public function test_purchase_return_reduces_stock_and_grni_then_can_be_reversed(): void
    {
        $warehouse = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);
        $lpb = PenerimaanBarang::where('document_type', 'GOODS')->whereDoesntHave('invoiceReceipts')->where('status', PenerimaanBarang::POSTED)->firstOrFail();
        $detail = $lpb->details()->where('jumlah_tersisa', '>', 0)->firstOrFail();
        $layer = LayerPersediaan::where('source_type', 'LPB_DETAIL')->where('source_id', $detail->id)->firstOrFail();
        $balance = StokGudang::where('gudang_id', $lpb->gudang_id)->where('bahan_id', $detail->id_bahan)->firstOrFail();
        $kategori = $detail->kategori;

        $stockBefore = (float) $balance->stok_tersedia;
        $layerBefore = (float) $layer->remaining_quantity;
        $onhandBefore = (float) $detail->bahan->stok_onhand;
        $returQty = min(1.0, $layerBefore);
        $this->assertGreaterThan(0, $returQty);

        $numbers = app(DocumentNumberService::class);
        $response = $this->actingAs($warehouse)->postJson(route('retur-pembelian.store'), [
            'no_retur' => $numbers->external('RTV'),
            'lpb_id' => $lpb->id,
            'tanggal' => today()->toDateString(),
            'alasan' => 'Barang rusak saat pemeriksaan gudang (integration test).',
            'details' => [
                ['lpb_detail_id' => $detail->id, 'jumlah_retur' => $returQty],
            ],
        ]);
        $response->assertCreated();
        $retur = ReturPembelian::findOrFail($response->json('data.id'));

        $this->assertSame($stockBefore - $returQty, (float) $balance->fresh()->stok_tersedia);
        $this->assertSame($layerBefore - $returQty, (float) $layer->fresh()->remaining_quantity);
        $this->assertSame($onhandBefore - $returQty, (float) $detail->bahan->fresh()->stok_onhand);

        $jurnal = \App\Models\Jurnal::with('details')->where('sumber_transaksi', 'RETUR_PEMBELIAN')->where('reff_id', $retur->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $jurnal->total_debit, (float) $jurnal->total_kredit, 0.01);
        $expectedAmount = round($returQty * (float) $detail->harga, 2);
        $this->assertTrue($jurnal->details->contains(
            fn($line) => (int) $line->coa_id === (int) $kategori->coa_clearing_lpb_id && abs((float) $line->debit - $expectedAmount) < 0.01
        ));
        $this->assertTrue($jurnal->details->contains(
            fn($line) => (int) $line->coa_id === (int) $kategori->coa_persediaan_id && abs((float) $line->kredit - $expectedAmount) < 0.01
        ));

        $overResponse = $this->actingAs($warehouse)->postJson(route('retur-pembelian.store'), [
            'no_retur' => $numbers->external('RTV'),
            'lpb_id' => $lpb->id,
            'tanggal' => today()->toDateString(),
            'alasan' => 'Percobaan retur melebihi stok tersedia.',
            'details' => [
                ['lpb_detail_id' => $detail->id, 'jumlah_retur' => $layerBefore + 1000],
            ],
        ]);
        $overResponse->assertStatus(422);

        app(InventoryReversalService::class)->reverseReturPembelian($retur, 'Koreksi integration test retur pembelian');

        $this->assertSame(ReturPembelian::REVERSED, $retur->fresh()->status);
        $this->assertSame($stockBefore, (float) $balance->fresh()->stok_tersedia);
        $this->assertSame($layerBefore, (float) $layer->fresh()->remaining_quantity);
        $this->assertDatabaseHas('wms_pembalikan_dokumen', ['document_type' => 'RETUR_PEMBELIAN', 'document_id' => $retur->id]);
    }

    public function test_every_payment_names_a_finance_user_that_actually_exists(): void
    {
        $total = PembayaranFaktur::count();

        $this->assertGreaterThan(0, $total, 'Data demo harus punya pembayaran untuk diperiksa.');

        $this->assertSame(
            0,
            PembayaranFaktur::whereDoesntHave('userFinance')->count(),
            'Empat seeder pernah menulis finance_user_id 13 padahal user itu tidak pernah ada, sehingga "diproses oleh" '
                . 'kosong di setiap pembayaran demo; constraint foreign key pada kolom itu kini menjaganya.'
        );
    }
}
