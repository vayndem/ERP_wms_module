<?php

namespace Tests\Feature;

use App\Models\Bahan;
use App\Models\Gudang;
use App\Models\KategoriBahan;
use App\Models\LayerPersediaan;
use App\Models\Supplier;
use App\Models\User;
use App\Services\DocumentNumberService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class DashboardAndReportingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_rejects_email_header_injection_characters(): void
    {
        $this->post(route('login.attempt'), [
            'email' => "operator@example.com\r\nBcc: attacker@example.com",
            'password' => 'secret',
        ])->assertSessionHasErrors('email');
    }

    public function test_control_center_is_available_to_warehouse_and_accounting(): void
    {
        $warehouse = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);
        $accounting = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);

        $this->actingAs($warehouse)->get(route('wms-control.index'))->assertOk();
        $this->actingAs($accounting)->get(route('wms-control.index'))->assertOk();
    }

    public function test_purchase_return_index_and_create_views_render(): void
    {
        $warehouse = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);

        $this->actingAs($warehouse)->get(route('retur-pembelian.index'))->assertOk()->assertSee('Retur Pembelian');
        $this->actingAs($warehouse)->get(route('retur-pembelian.create'))->assertOk()->assertSee('Buat Retur Pembelian Baru');
    }

    public function test_lpb_index_and_create_views_render_without_a_stray_detail_row_template(): void
    {
        $warehouse = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);

        $index = $this->actingAs($warehouse)->get(route('penerimaan-barang.index'));
        $index->assertOk()->assertSee('Daftar Penerimaan');
        $index->assertSeeInOrder(['x-for="row in rows" :key="row.id"', '<tbody>', 'toggleExpand(row.id)', 'expanded[row.id]', '</tbody>'], false);

        $this->actingAs($warehouse)->get(route('penerimaan-barang.create'))->assertOk()->assertSee('LPB');
    }

    public function test_request_index_and_create_views_render_without_a_stray_detail_row_template(): void
    {
        $warehouse = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);

        $index = $this->actingAs($warehouse)->get(route('request.index'));
        $index->assertOk()->assertSee('Daftar Request');
        $index->assertSeeInOrder(['x-for="row in rows" :key="row.id"', '<tbody>', 'toggleExpand(row.id)', 'expanded[row.id]', '</tbody>'], false);

        $this->actingAs($warehouse)->get(route('request.create'))->assertOk();
    }

    public function test_pembelian_index_view_renders_without_a_stray_detail_row_template(): void
    {
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);

        $index = $this->actingAs($purchasing)->get(route('pembelian.index'));
        $index->assertOk()->assertSee('Daftar Transaksi Pembelian');
        $index->assertSeeInOrder(['x-for="row in rows" :key="row.no_po"', '<tbody>', 'toggleExpand(row.no_po)', 'expanded[row.no_po]', '</tbody>'], false);
    }

    public function test_invoice_lpb_create_view_renders_supplier_and_receipt_picker(): void
    {
        $accounting = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);

        $create = $this->actingAs($accounting)->get(route('faktur-pembelian.create'));
        $create->assertOk();
        $create->assertSee('Pilih Supplier');
        $create->assertSee('Pilih LPB / BAP Supplier');
        $create->assertSee('function invoiceCreateForm', false);
    }

    public function test_every_role_dashboard_renders_its_task_list(): void
    {
        $roles = [
            User::ROLE_PURCHASING => 'Semua Tugas Purchasing',
            User::ROLE_FINANCE => 'Semua Tugas Finance',
            User::ROLE_WAREHOUSE => 'Semua Tugas Gudang',
            User::ROLE_PRODUCTION => 'Semua Tugas Produksi',
            User::ROLE_ACCOUNTING => 'Semua Tugas Accounting',
            User::ROLE_ACCOUNTING_MANAGER => 'Semua Tugas Accounting',
            User::ROLE_SUPER_ADMIN => 'Seluruh Tugas Terbuka',
        ];

        foreach ($roles as $role => $expectedHeading) {
            $user = User::factory()->create(['type' => $role]);
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();

            $this->actingAs($user)->get(route('dashboard'))
                ->assertOk()
                ->assertSee($expectedHeading)
                ->assertSee('Tugas Saya')
                ->assertSee('Tenggat Terdekat')
                ->assertSee('Progres Pekerjaan');

            $jumlahQuery = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();
            $this->assertLessThan(90, $jumlahQuery, "Dashboard role {$role} memakai {$jumlahQuery} query.");
        }
    }

    public function test_dashboard_reminders_surface_upcoming_deadlines_with_a_day_countdown(): void
    {
        $warehouse = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);
        $layer = LayerPersediaan::where('remaining_quantity', '>', 0)->where('stock_status', 'AVAILABLE')->firstOrFail();
        \App\Models\PembagianGudang::create([
            'user_id' => $warehouse->id,
            'gudang_id' => $layer->gudang_id,
            'boleh_menerima' => true,
            'boleh_npk' => true,
            'boleh_transfer' => true,
            'boleh_opname' => true,
        ]);
        $lot = \App\Models\LotPersediaan::create([
            'bahan_id' => $layer->bahan_id,
            'lot_number' => 'DASH-REMINDER-1',
            'quality_status' => 'RELEASED',
            'expires_at' => today()->addDays(5),
        ]);
        $layer->update(['inventory_lot_id' => $lot->id]);

        $this->actingAs($warehouse)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Tenggat Terdekat')
            ->assertSee('DASH-REMINDER-1')
            ->assertSee('5 hari lagi');

        $lot->update(['expires_at' => today()->subDays(2)]);
        $this->actingAs($warehouse)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Lewat 2 hari');
    }

    public function test_finance_dashboard_counts_available_advances_without_an_n_plus_one(): void
    {
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($finance)->get(route('dashboard'))->assertOk();
        $jumlahQuery = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        $this->assertLessThan(
            40,
            $jumlahQuery,
            "Dashboard Finance memakai {$jumlahQuery} query — cek apakah ada N+1 yang kembali (dulu uang muka dihitung satu query per baris)."
        );
    }

    public function test_non_purchasing_role_can_submit_and_track_a_material_request_to_fulfillment(): void
    {
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);
        $numbers = app(DocumentNumberService::class);
        $category = \App\Models\KategoriBahan::where('katnama', 'Bahan Baku Paper')->firstOrFail();
        $gudang = Gudang::where('nama', 'Gudang Utama')->firstOrFail();
        $supplier = Supplier::create(['nama' => 'Supplier Uji Request', 'alamat' => 'Jl. Request', 'telp' => '0800000098', 'pembayaran' => 'Transfer']);

        $this->actingAs($purchasing)->getJson(route('request.create'))->assertStatus(403);

        $this->actingAs($finance)->postJson(route('request.store'), [
            'no_request' => $numbers->internal('REQ', 'PO'),
            'items' => [[
                'nama_barang' => 'Barang Uji Request Baru',
                'jumlah_minta' => 10,
                'satuan' => 'PCS',
                'kategori' => $category->id,
                'tipe_barang' => $category->id,
                'tipe_gudang' => $gudang->id,
            ]],
        ])->assertOk();

        $materialRequest = \App\Models\MaterialRequest::latest('id')->firstOrFail();
        $this->assertSame($finance->id, $materialRequest->requested_by);
        $this->assertSame(\App\Models\MaterialRequest::PENDING, $materialRequest->status);

        $detail = $materialRequest->details()->firstOrFail();
        $this->assertNull($detail->bahan_id);

        $this->actingAs($finance)->postJson(route('request.processApprove', $materialRequest->id), [
            'items' => [$detail->id => ['jumlah_acc' => 10]],
        ])->assertStatus(403);

        $this->actingAs($purchasing)->postJson(route('request.processApprove', $materialRequest->id), [
            'items' => [$detail->id => ['jumlah_acc' => 10]],
        ])->assertOk();

        $materialRequest->refresh();
        $detail->refresh();
        $this->assertSame(\App\Models\MaterialRequest::APPROVED, $materialRequest->status);
        $this->assertNotNull($detail->bahan_id);
        $bahan = Bahan::findOrFail($detail->bahan_id);
        $this->assertSame('Barang Uji Request Baru', $bahan->nama);

        $ownIndex = $this->actingAs($finance)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('request.index'))
            ->assertOk()->json('data');
        $this->assertTrue(collect($ownIndex)->contains(fn ($row) => (int) $row['id'] === $materialRequest->id));

        $poNumber = $this->actingAs($purchasing)->postJson(route('pembelian.store'), [
            'tanggal' => today()->toDateString(),
            'supplier_id' => $supplier->id,
            'gudang_id' => $gudang->id,
            'is_ppn' => 0,
            'details' => [[
                'bahan_id' => $bahan->id,
                'harga' => 5000,
                'jumlah' => 10,
                'request_detail_id' => $detail->id,
            ]],
        ])->assertCreated()->json('data.no_po');

        $materialRequest->refresh();
        $detail->refresh();
        $this->assertEqualsWithDelta(10.0, (float) $detail->realisasi, 0.000001);
        $this->assertSame(\App\Models\MaterialRequest::FULFILLED, $materialRequest->status);
    }

    public function test_material_request_pdf_and_excel_reports_are_scoped_to_own_requests_for_non_reviewers(): void
    {
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);
        $production = User::factory()->create(['type' => User::ROLE_PRODUCTION]);
        $numbers = app(DocumentNumberService::class);
        $category = \App\Models\KategoriBahan::where('katnama', 'Bahan Baku Paper')->firstOrFail();
        $gudang = Gudang::where('nama', 'Gudang Utama')->firstOrFail();

        $this->actingAs($finance)->postJson(route('request.store'), [
            'no_request' => $numbers->internal('REQ', 'PO'),
            'items' => [[
                'nama_barang' => 'Barang Uji Scoping Report',
                'jumlah_minta' => 5,
                'satuan' => 'PCS',
                'kategori' => $category->id,
                'tipe_barang' => $category->id,
                'tipe_gudang' => $gudang->id,
            ]],
        ])->assertOk();
        $materialRequest = \App\Models\MaterialRequest::latest('id')->firstOrFail();

        \Maatwebsite\Excel\Facades\Excel::fake();
        \Maatwebsite\Excel\Facades\Excel::matchByRegex();
        $this->actingAs($production)->get(route('request.report.excel'))->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('/^daftar-request-\d{8}-\d{6}\.xlsx$/', function (\App\Exports\GenericTableExport $export) use ($materialRequest) {
            $noRequests = $export->collection()->pluck('no_request');
            return !$noRequests->contains($materialRequest->no_request);
        });

        $this->actingAs($production)->get(route('request.report.pdf'))->assertOk();

        $superAdmin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        \Maatwebsite\Excel\Facades\Excel::fake();
        \Maatwebsite\Excel\Facades\Excel::matchByRegex();
        $this->actingAs($superAdmin)->get(route('request.report.excel'))->assertOk();
        \Maatwebsite\Excel\Facades\Excel::assertDownloaded('/^daftar-request-\d{8}-\d{6}\.xlsx$/', function (\App\Exports\GenericTableExport $export) use ($materialRequest) {
            return $export->collection()->pluck('no_request')->contains($materialRequest->no_request);
        });
    }

    public function test_generic_and_financial_statement_excel_exports_download_successfully(): void
    {
        \Maatwebsite\Excel\Facades\Excel::fake();

        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);
        $accounting = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $warehouse = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);

        $this->actingAs($finance)->get(route('request.report.excel'))->assertOk();
        $this->actingAs($accounting)->get(route('aset.report.excel'))->assertOk();
        $this->actingAs($accounting)->get(route('financial-statements.neraca-saldo.excel'))->assertOk();
        $this->actingAs($warehouse)->get(route('stock-opname.report.excel'))->assertOk();
    }

    public function test_generic_table_export_actually_generates_a_valid_spreadsheet(): void
    {
        $finance = User::factory()->create(['type' => User::ROLE_FINANCE]);

        $response = $this->actingAs($finance)->get(route('faktur-pembelian.report.excel'));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            $response->headers->get('content-type')
        );
    }

    public function test_executive_dashboard_renders_for_accounting_and_manager_but_not_other_roles(): void
    {
        $accounting = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $manager = User::factory()->create(['type' => User::ROLE_ACCOUNTING_MANAGER]);
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);

        $this->actingAs($accounting)->get(route('executive-dashboard.index'))
            ->assertOk()
            ->assertSee('Dashboard Eksekutif')
            ->assertSee('Tren Nilai Persediaan')
            ->assertSee('Aging Hutang Supplier')
            ->assertSee('Top 5 Supplier')
            ->assertSee('Biaya per Kategori Bahan');

        $this->actingAs($manager)->get(route('executive-dashboard.index'))->assertOk();

        $this->actingAs($purchasing)->get(route('executive-dashboard.index'))->assertForbidden();
    }

}
