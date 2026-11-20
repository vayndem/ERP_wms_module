<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Aset;
use App\Models\KategoriAset;
use App\Models\BaganAkun;
use App\Models\FakturPembelian;
use App\Models\PembayaranFaktur;
use App\Models\PenerimaanJasa;
use App\Models\KategoriJasa;
use App\Models\PesananJasa;
use App\Models\PenerimaanBarang;
use App\Models\Supplier;
use App\Services\AsetAccountingService;
use App\Services\WmsAccountingService;
use App\Services\DocumentNumberService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AssetAndServiceDemoSeeder extends Seeder
{
    public function run(): void
    {
        Auth::setUser(User::where('email', 'accounting@wms.local')->firstOrFail());
        DB::transaction(function () {
            $numbers = app(DocumentNumberService::class);
            if (!Aset::where('name', '[DEMO] Laptop Accounting')->exists()) {
                $category = KategoriAset::where('code', 'EQUIPMENT')->firstOrFail();
                $asset = Aset::create([
                    'nomor_aset' => $numbers->financial('AS', today()->subYears(1)),
                    'kategori_aset_id' => $category->id,
                    'name' => '[DEMO] Laptop Accounting',
                    'serial_number' => 'DEMO-SN-001',
                    'location' => 'Ruang Accounting',
                    'responsible_person' => 'Accounting Demo',
                    'condition' => 'BAIK',
                    'acquisition_date' => today()->subYears(1),
                    'acquisition_type' => 'OPENING_BALANCE',
                    'acquisition_credit_coa_id' => BaganAkun::where('kode_akun', '3102')->value('id'),
                    'acquisition_cost' => 4000000,
                    'residual_value' => 0,
                    'useful_life_months' => 48,
                    'depreciation_method' => 'STRAIGHT_LINE',
                    'opening_accumulated_depreciation' => 1000000,
                    'accumulated_depreciation' => 1000000,
                    'book_value' => 3000000,
                    'status' => 'ACTIVE',
                    'created_by' => 33,
                ]);
                app(AsetAccountingService::class)->postAcquisition($asset);
                app(AsetAccountingService::class)->depreciate($asset, [
                    'posting_date' => today(),
                    'period_label' => 'Penyusutan manual demo',
                    'amount' => 250000,
                    'reason' => 'Contoh nominal manual berbeda dari jadwal otomatis',
                ]);
            }
            if (!PesananJasa::where('notes', 'PO jasa demo terpisah dari barang')->exists()) {
                Auth::setUser(User::where('email', 'purchasing@wms.local')->firstOrFail());
                $supplier = Supplier::where('nama', 'PT. Global Supply Indonesia')->firstOrFail();
                $bank = BaganAkun::where('kode_akun', '1102')->firstOrFail();
                $accounting = app(WmsAccountingService::class);
                $po = PesananJasa::create([
                    'no_po' => $numbers->financial('PJ', today()->subDays(7)),
                    'document_type' => 'SERVICE',
                    'tanggal' => today()->subDays(7),
                    'supplier_id' => $supplier->id,
                    'no_order' => '-',
                    'untuk_perhatian' => 'Vendor Jasa',
                    'term' => '14 hari',
                    'notes' => 'PO jasa demo terpisah dari barang',
                    'total_exclude' => 8000000,
                    'total_include' => 8000000,
                    'grand_total' => 8000000
                ]);
                $operational = KategoriJasa::where('code', KategoriJasa::OPERATIONAL)->firstOrFail();
                $production = KategoriJasa::where('code', KategoriJasa::PRODUCTION)->firstOrFail();
                $op = $po->serviceDetails()->create([
                    'service_category_id' => $operational->id,
                    'id_kategori' => $operational->kategori_bahan_id,
                    'service_type' => $operational->code,
                    'description' => 'Servis AC ruang produksi',
                    'quantity' => 1,
                    'unit' => 'JOB',
                    'unit_price' => 2000000,
                    'subtotal' => 2000000,
                    'accepted_amount' => 2000000
                ]);
                $prod = $po->serviceDetails()->create([
                    'service_category_id' => $production->id,
                    'id_kategori' => $production->kategori_bahan_id,
                    'service_type' => $production->code,
                    'description' => 'Jasa laminasi produksi',
                    'quantity' => 1,
                    'unit' => 'JOB',
                    'unit_price' => 6000000,
                    'subtotal' => 6000000,
                    'accepted_amount' => 6000000
                ]);
                $bap = PenerimaanJasa::create([
                    'id_lpb' => $numbers->external('BAP', today()->subDays(2)),
                    'document_type' => 'SERVICE_BAP',
                    'tanggal' => today()->subDays(2),
                    'no_po' => $po->no_po,
                    'no_sj' => 'DEMO-BA-001',
                    'id_user' => 5,
                    'status' => PenerimaanBarang::POSTED,
                    'jenis_lpb' => 3,
                    'kunci' => 1
                ]);
                $bap->serviceDetails()->create([
                    'service_po_detail_id' => $op->id,
                    'id_kategori' => $op->id_kategori,
                    'progress_percent' => 100,
                    'amount' => 2000000,
                    'department_cost_center' => 'Produksi'
                ]);
                $detail = $bap->serviceDetails()->create([
                    'service_po_detail_id' => $prod->id,
                    'id_kategori' => $prod->id_kategori,
                    'progress_percent' => 100,
                    'amount' => 6000000,
                ]);
                $detail->allocations()->createMany([
                    ['datapesanan_code' => 'DEMO-DP-001', 'percentage' => 60, 'amount' => 3600000],
                    ['datapesanan_code' => 'DEMO-DP-002', 'percentage' => 40, 'amount' => 2400000],
                ]);

                $invoice = FakturPembelian::create([
                    'no_invoice' => 'DEMO-SRV-INV-001',
                    'kode_supplier' => $supplier->id,
                    'tanggal' => today()->subDay(),
                    'tgl_deadline_pembayaran' => today()->addDays(13),
                    'sub_total' => 8000000,
                    'jenis_pajak' => 'NON_PPN',
                    'dpp_ppn' => 0,
                    'tarif_ppn' => 0,
                    'ppn' => 0,
                    'jenis_pph' => 'PPH23',
                    'dasar_pph' => 8000000,
                    'tarif_pph' => 2,
                    'diskon' => 0,
                    'ongkir' => 0,
                    'pph' => 0,
                    'grand_total' => 8000000,
                    'total_pembayaran' => 3000000,
                    'sisa_tagihan' => 5000000,
                    'note' => 'Invoice jasa demo untuk melihat alur penuh BAP -> Invoice -> Pembayaran',
                    'status' => FakturPembelian::PARTIALLY_PAID,
                ]);
                $invoice->receipts()->create(['lpb_id' => $bap->id, 'amount' => 8000000]);
                $bap->update(['no_invoice' => $invoice->no_invoice]);
                $accounting->postInvoice($invoice);

                $partialPayment = PembayaranFaktur::create([
                    'payment_number' => $numbers->financial('PY', today()),
                    'invoice_lpb_id' => $invoice->id,
                    'tanggal_pembayaran' => today(),
                    'metode_pembayaran' => 'Transfer Bank BCA - Parsial',
                    'coa_kas_bank_id' => $bank->id,
                    'jumlah_pembayaran' => 2840000,
                    'potongan_pph' => 160000,
                    'potongan_materai' => 0,
                    'biaya_transfer_bank' => 6500,
                    'selisih_bayar' => 0,
                    'jenis_selisih' => null,
                    'coa_selisih_id' => null,
                    'kelebihan_pembayaran' => 0,
                    'total_transaksi_pengurang_hutang' => 3000000,
                    'keterangan' => 'Pembayaran parsial invoice jasa demo',
                    'finance_user_id' => User::where('type', User::ROLE_FINANCE)->orderBy('id')->value('id'),
                ]);
                $accounting->postPayment($partialPayment);

                $poLunas = PesananJasa::create([
                    'no_po' => $numbers->financial('PJ', today()->subDays(5)),
                    'document_type' => 'SERVICE',
                    'tanggal' => today()->subDays(5),
                    'supplier_id' => $supplier->id,
                    'no_order' => '-',
                    'untuk_perhatian' => 'Vendor Jasa Lunas',
                    'term' => 'Cash',
                    'notes' => 'PO jasa demo lunas penuh untuk dashboard dan histori',
                    'total_exclude' => 1500000,
                    'total_include' => 1500000,
                    'grand_total' => 1500000,
                ]);
                $poLunasDetail = $poLunas->serviceDetails()->create([
                    'service_category_id' => $operational->id,
                    'id_kategori' => $operational->kategori_bahan_id,
                    'service_type' => $operational->code,
                    'description' => 'Jasa kalibrasi timbangan gudang',
                    'quantity' => 1,
                    'unit' => 'JOB',
                    'unit_price' => 1500000,
                    'subtotal' => 1500000,
                    'accepted_amount' => 1500000,
                ]);
                $bapLunas = PenerimaanJasa::create([
                    'id_lpb' => $numbers->external('BAP', today()->subDays(3)),
                    'document_type' => 'SERVICE_BAP',
                    'tanggal' => today()->subDays(3),
                    'no_po' => $poLunas->no_po,
                    'no_sj' => 'DEMO-BA-002',
                    'id_user' => 5,
                    'status' => PenerimaanBarang::POSTED,
                    'jenis_lpb' => 3,
                    'kunci' => 1
                ]);
                $bapLunas->serviceDetails()->create([
                    'service_po_detail_id' => $poLunasDetail->id,
                    'id_kategori' => $poLunasDetail->id_kategori,
                    'progress_percent' => 100,
                    'amount' => 1500000,
                    'department_cost_center' => 'Gudang',
                ]);

                $invoiceLunas = FakturPembelian::create([
                    'no_invoice' => 'DEMO-SRV-INV-002',
                    'kode_supplier' => $supplier->id,
                    'tanggal' => today()->subDays(2),
                    'tgl_deadline_pembayaran' => today()->addDays(5),
                    'sub_total' => 1500000,
                    'jenis_pajak' => 'NON_PPN',
                    'dpp_ppn' => 0,
                    'tarif_ppn' => 0,
                    'ppn' => 0,
                    'jenis_pph' => 'PPH23',
                    'dasar_pph' => 1500000,
                    'tarif_pph' => 2,
                    'diskon' => 0,
                    'ongkir' => 0,
                    'pph' => 30000,
                    'grand_total' => 1500000,
                    'total_pembayaran' => 1500000,
                    'sisa_tagihan' => 0,
                    'note' => 'Invoice jasa demo lunas penuh',
                    'status' => FakturPembelian::PAID,
                ]);
                $invoiceLunas->receipts()->create(['lpb_id' => $bapLunas->id, 'amount' => 1500000]);
                $bapLunas->update(['no_invoice' => $invoiceLunas->no_invoice]);
                $accounting->postInvoice($invoiceLunas);

                $fullPayment = PembayaranFaktur::create([
                    'payment_number' => $numbers->financial('PY', today()->addDay()),
                    'invoice_lpb_id' => $invoiceLunas->id,
                    'tanggal_pembayaran' => today()->addDay(),
                    'metode_pembayaran' => 'Transfer Bank BCA - Lunas',
                    'coa_kas_bank_id' => $bank->id,
                    'jumlah_pembayaran' => 1470000,
                    'potongan_pph' => 30000,
                    'potongan_materai' => 0,
                    'biaya_transfer_bank' => 0,
                    'selisih_bayar' => 0,
                    'jenis_selisih' => null,
                    'coa_selisih_id' => null,
                    'kelebihan_pembayaran' => 0,
                    'total_transaksi_pengurang_hutang' => 1500000,
                    'keterangan' => 'Pelunasan invoice jasa demo',
                    'finance_user_id' => User::where('type', User::ROLE_FINANCE)->orderBy('id')->value('id'),
                ]);
                $accounting->postPayment($fullPayment);
            }
        });
        $this->command?->info('Demo asset dan skenario jasa lengkap dibuat: PO, BAP, invoice parsial, pembayaran parsial, dan invoice lunas.');
    }
}
