<?php

namespace Tests\Feature;

use App\Models\FakturPembelian;
use App\Models\FakturPenjualan;
use App\Models\Pelanggan;
use App\Models\Supplier;
use App\Models\User;
use App\Services\KepatuhanPajakService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KepatuhanPajakTest extends TestCase
{
    use DatabaseTransactions;

    private const MASA = '2031-03';

    private int $urutan = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.npwp_perusahaan' => '0123456789012000',
            'app.nitku_perusahaan' => '0123456789012000000000',
        ]);
    }

    private function pelanggan(array $ubah = []): Pelanggan
    {
        return Pelanggan::create(array_merge([
            'kode' => 'CUST-KP-' . ++$this->urutan . random_int(100, 999),
            'nama' => 'PT Kepatuhan Pelanggan',
            'npwp' => '0123456789012001',
            'nitku' => '0123456789012001000000',
            'tipe_identitas' => 'NPWP',
            'termin_hari' => 30,
            'is_active' => true,
        ], $ubah));
    }

    private function supplier(array $ubah = []): Supplier
    {
        return Supplier::create(array_merge([
            'nama' => 'PT Kepatuhan Supplier ' . ++$this->urutan . random_int(100, 999),
            'alamat' => 'Jl. Uji',
            'telp' => '021',
            'pembayaran' => 'TRANSFER',
            'npwp' => '0123456789012002',
            'nitku' => '0123456789012002000000',
            'tipe_identitas' => 'NPWP',
        ], $ubah));
    }

    private function fakturJual(Pelanggan $pelanggan, array $ubah = []): FakturPenjualan
    {
        return FakturPenjualan::create(array_merge([
            'nomor' => 'FJL-KP-' . ++$this->urutan . random_int(1000, 9999),
            'tanggal' => self::MASA . '-10',
            'jatuh_tempo' => self::MASA . '-25',
            'pelanggan_id' => $pelanggan->id,
            'no_faktur_pajak' => '04002600000' . str_pad((string) random_int(100000, 999999), 6, '0'),
            'tanggal_faktur_pajak' => self::MASA . '-10',
            'is_ppn' => true,
            'tarif_ppn' => 11,
            'total_dpp' => 100000,
            'total_ppn' => 11000,
            'grand_total' => 111000,
            'sisa_tagihan' => 111000,
            'status' => FakturPenjualan::POSTED,
        ], $ubah));
    }

    private function fakturBeli(Supplier $supplier, array $ubah = []): FakturPembelian
    {
        return FakturPembelian::create(array_merge([
            'no_invoice' => 'INV-KP-' . ++$this->urutan . random_int(1000, 9999),
            'kode_supplier' => $supplier->id,
            'tanggal' => self::MASA . '-12',
            'sub_total' => 100000,
            'jenis_pajak' => 'PPN',
            'dpp_ppn' => 100000,
            'tarif_ppn' => 11,
            'ppn' => 11000,
            'no_faktur_pajak' => '01000' . str_pad((string) random_int(100000, 999999), 12, '0'),
            'tanggal_faktur_pajak' => self::MASA . '-12',
            'dasar_pph' => 0,
            'tarif_pph' => 0,
            'diskon' => 0,
            'ongkir' => 0,
            'ppn_impor' => 0,
            'pph' => 0,
            'grand_total' => 111000,
            'total_pembayaran' => 0,
            'sisa_tagihan' => 111000,
            'match_status' => 'MATCHED',
            'status' => FakturPembelian::UNPAID,
        ], $ubah));
    }

    private function periksa(): array
    {
        return app(KepatuhanPajakService::class)->periksa(self::MASA);
    }

    private function kategori(array $hasil): array
    {
        return $hasil['temuan']->pluck('kategori')->unique()->values()->all();
    }

    public function test_a_clean_period_has_no_findings_and_a_zero_summary_for_every_category(): void
    {
        $this->fakturJual($this->pelanggan());
        $this->fakturBeli($this->supplier());

        $hasil = $this->periksa();

        $this->assertSame([], $hasil['temuan']->all());
        $this->assertSame(0, $hasil['total']);
        $this->assertSame(KepatuhanPajakService::KATEGORI, array_keys($hasil['ringkasan']));
        $this->assertSame(0, array_sum($hasil['ringkasan']));
    }

    public function test_missing_identity_is_reported_for_a_customer_and_a_supplier(): void
    {
        $this->fakturJual($this->pelanggan(['npwp' => null]));
        $this->fakturBeli($this->supplier(['npwp' => null]));

        $hasil = $this->periksa();
        $kosong = $hasil['temuan']->where('kategori', KepatuhanPajakService::IDENTITAS_KOSONG);

        $this->assertSame(['Pelanggan', 'Supplier'], $kosong->pluck('dokumen')->sort()->values()->all());
        $this->assertSame([KepatuhanPajakService::IDENTITAS_KOSONG], $this->kategori($hasil));
    }

    public function test_a_nik_party_needs_a_nik_not_an_npwp_or_nitku(): void
    {
        $this->fakturJual($this->pelanggan(['tipe_identitas' => 'NIK', 'npwp' => null, 'nitku' => null, 'nik' => '3174061502560010']));
        $this->assertSame(0, $this->periksa()['total']);

        $this->fakturJual($this->pelanggan(['tipe_identitas' => 'NIK', 'npwp' => '0123456789012001', 'nitku' => null, 'nik' => null]));
        $hasil = $this->periksa();

        $this->assertSame(1, $hasil['ringkasan'][KepatuhanPajakService::IDENTITAS_KOSONG]);
        $this->assertStringContainsString('NIK', $hasil['temuan']->first()['keterangan']);
    }

    public function test_malformed_identity_values_are_reported_and_left_untouched(): void
    {
        $pelanggan = $this->pelanggan(['npwp' => '12345', 'nitku' => '999', 'nik' => '777']);
        $this->fakturJual($pelanggan);

        $hasil = $this->periksa();

        $this->assertSame(3, $hasil['ringkasan'][KepatuhanPajakService::IDENTITAS_FORMAT_SALAH]);
        $this->assertSame('12345', $pelanggan->fresh()->npwp);
    }

    public function test_identity_of_a_party_without_ppn_or_withholding_documents_is_not_demanded(): void
    {
        $this->fakturJual($this->pelanggan(['npwp' => null]), ['is_ppn' => false, 'total_ppn' => 0]);
        $this->fakturBeli($this->supplier(['npwp' => null]), [
            'jenis_pajak' => 'NON_PPN', 'ppn' => 0, 'dpp_ppn' => 0, 'no_faktur_pajak' => null, 'tanggal_faktur_pajak' => null,
        ]);

        $this->assertSame(0, $this->periksa()['total']);
    }

    public function test_malformed_tax_serial_numbers_are_reported_on_both_sides(): void
    {
        $this->fakturJual($this->pelanggan(), ['no_faktur_pajak' => '123']);
        $this->fakturBeli($this->supplier(), ['no_faktur_pajak' => '123-456']);

        $hasil = $this->periksa();

        $this->assertSame(2, $hasil['ringkasan'][KepatuhanPajakService::NSFP_FORMAT_SALAH]);
        $this->assertSame(['Faktur penjualan', 'Faktur pembelian'], $hasil['temuan']->pluck('dokumen')->all());
    }

    public function test_the_same_purchase_nsfp_from_one_supplier_is_a_duplicate_but_not_across_suppliers(): void
    {
        $supplierA = $this->supplier();
        $supplierB = $this->supplier();
        $nsfp = '01000000000000777';

        $this->fakturBeli($supplierA, ['no_faktur_pajak' => $nsfp]);
        $this->fakturBeli($supplierB, ['no_faktur_pajak' => $nsfp]);

        $this->assertSame(0, $this->periksa()['ringkasan'][KepatuhanPajakService::NSFP_DUPLIKAT]);

        $this->fakturBeli($supplierA, ['no_faktur_pajak' => $nsfp]);

        $hasil = $this->periksa();
        $this->assertSame(2, $hasil['ringkasan'][KepatuhanPajakService::NSFP_DUPLIKAT]);
        $this->assertSame([$supplierA->nama], $hasil['temuan']->pluck('pihak')->unique()->values()->all());
    }

    public function test_a_duplicate_nsfp_is_found_when_the_twin_sits_in_another_period_and_unrelated_duplicates_are_ignored(): void
    {
        $supplier = $this->supplier();
        $nsfp = '01000000000000999';

        $this->fakturBeli($supplier, ['no_faktur_pajak' => $nsfp]);
        $this->fakturBeli($supplier, ['no_faktur_pajak' => $nsfp, 'tanggal' => '2031-01-10', 'tanggal_faktur_pajak' => '2031-01-10']);

        $lain = $this->supplier();
        $this->fakturBeli($lain, ['no_faktur_pajak' => '01000000000001111', 'tanggal' => '2031-02-10', 'tanggal_faktur_pajak' => '2031-02-10']);
        $this->fakturBeli($lain, ['no_faktur_pajak' => '01000000000001111', 'tanggal' => '2031-02-11', 'tanggal_faktur_pajak' => '2031-02-11']);

        $hasil = $this->periksa();

        $this->assertSame(1, $hasil['ringkasan'][KepatuhanPajakService::NSFP_DUPLIKAT]);
        $this->assertSame([$supplier->nama], $hasil['temuan']->where('kategori', KepatuhanPajakService::NSFP_DUPLIKAT)->pluck('pihak')->all());
    }

    public function test_a_voided_purchase_invoice_does_not_make_its_nsfp_a_duplicate(): void
    {
        $supplier = $this->supplier();
        $nsfp = '01000000000000888';

        $this->fakturBeli($supplier, ['no_faktur_pajak' => $nsfp, 'status' => FakturPembelian::VOID]);
        $this->fakturBeli($supplier, ['no_faktur_pajak' => $nsfp]);

        $this->assertSame(0, $this->periksa()['ringkasan'][KepatuhanPajakService::NSFP_DUPLIKAT]);
    }

    public function test_an_invalid_company_npwp_or_nitku_is_reported(): void
    {
        config(['app.npwp_perusahaan' => '', 'app.nitku_perusahaan' => '123']);

        $hasil = $this->periksa();

        $this->assertSame(2, $hasil['ringkasan'][KepatuhanPajakService::NPWP_PERUSAHAAN_TIDAK_VALID]);
        $this->assertSame(
            ['NPWP_PERUSAHAAN', 'NITKU_PERUSAHAAN'],
            $hasil['temuan']->where('kategori', KepatuhanPajakService::NPWP_PERUSAHAAN_TIDAK_VALID)->pluck('nomor')->all()
        );
    }

    public function test_ppn_invoices_without_a_tax_invoice_date_are_reported(): void
    {
        $this->fakturJual($this->pelanggan(), ['tanggal_faktur_pajak' => null]);
        $this->fakturBeli($this->supplier(), ['tanggal_faktur_pajak' => null]);
        $this->fakturJual($this->pelanggan(), ['tanggal_faktur_pajak' => null, 'is_ppn' => false, 'no_faktur_pajak' => null]);

        $hasil = $this->periksa();

        $this->assertSame(2, $hasil['ringkasan'][KepatuhanPajakService::FAKTUR_PPN_TANPA_TANGGAL_FAKTUR_PAJAK]);
    }

    public function test_withholding_without_an_object_code_is_reported_until_one_is_chosen(): void
    {
        $faktur = $this->fakturBeli($this->supplier(), [
            'jenis_pajak' => 'NON_PPN', 'ppn' => 0, 'dpp_ppn' => 0, 'no_faktur_pajak' => null, 'tanggal_faktur_pajak' => null,
            'jenis_pph' => 'PPH23', 'dasar_pph' => 100000, 'tarif_pph' => 2,
        ]);

        $hasil = $this->periksa();
        $this->assertSame(1, $hasil['ringkasan'][KepatuhanPajakService::PPH_TANPA_KODE_OBJEK]);

        $faktur->update(['kode_objek_pajak' => '24-104-29']);
        $this->assertSame(0, $this->periksa()['ringkasan'][KepatuhanPajakService::PPH_TANPA_KODE_OBJEK]);
    }

    public function test_pph22_has_no_verified_object_code_so_it_is_not_flagged(): void
    {
        $this->fakturBeli($this->supplier(), [
            'jenis_pajak' => 'NON_PPN', 'ppn' => 0, 'dpp_ppn' => 0, 'no_faktur_pajak' => null, 'tanggal_faktur_pajak' => null,
            'jenis_pph' => 'PPH22', 'dasar_pph' => 100000, 'tarif_pph' => 1.5,
        ]);

        $this->assertSame(0, $this->periksa()['ringkasan'][KepatuhanPajakService::PPH_TANPA_KODE_OBJEK]);
    }

    public function test_documents_outside_the_period_and_drafts_are_ignored(): void
    {
        $this->fakturJual($this->pelanggan(['npwp' => null]), ['tanggal' => '2031-04-01', 'tanggal_faktur_pajak' => null]);
        $this->fakturJual($this->pelanggan(['npwp' => null]), ['status' => FakturPenjualan::DRAFT, 'tanggal_faktur_pajak' => null]);

        $this->assertSame(0, array_sum(array_diff_key(
            $this->periksa()['ringkasan'],
            [KepatuhanPajakService::NPWP_PERUSAHAAN_TIDAK_VALID => 0]
        )));
    }

    public function test_the_preflight_and_the_report_page_never_write_to_the_database(): void
    {
        $this->fakturJual($this->pelanggan(['npwp' => '12']), ['tanggal_faktur_pajak' => null, 'no_faktur_pajak' => 'salah']);
        $this->fakturBeli($this->supplier(), ['no_faktur_pajak' => 'salah']);

        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
        $tulisan = [];

        DB::listen(function ($query) use (&$tulisan) {
            if (preg_match('/^\s*(insert|update|delete|replace)\s/i', $query->sql)) {
                $tulisan[] = $query->sql;
            }
        });

        $this->periksa();
        $this->actingAs($admin)->get(route('laporan-pajak-djp.index', ['masa' => self::MASA]))->assertOk();

        $this->assertSame([], $tulisan, 'Preflight kepatuhan harus baca-saja.');
    }

    public function test_the_existing_tax_report_page_shows_the_compliance_summary_with_its_findings(): void
    {
        $this->fakturJual($this->pelanggan(['npwp' => null]), ['no_faktur_pajak' => '123']);
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);

        $this->actingAs($admin)->get(route('laporan-pajak-djp.index', ['masa' => self::MASA]))
            ->assertOk()
            ->assertSee('Pemeriksaan Kepatuhan Data Pajak')
            ->assertSee('IDENTITAS_KOSONG')
            ->assertSee('NSFP_FORMAT_SALAH')
            ->assertSee('PPH_TANPA_KODE_OBJEK');
    }

    public function test_the_seeded_demo_data_passes_the_preflight_for_every_period_that_has_documents(): void
    {
        $masa = FakturPenjualan::whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
            ->get()->pluck('tanggal')->map(fn ($tanggal) => $tanggal->format('Y-m'))
            ->merge(FakturPembelian::pluck('tanggal')->map(fn ($tanggal) => $tanggal->format('Y-m')))
            ->unique()->values();

        $this->assertNotEmpty($masa);

        foreach ($masa as $periode) {
            $hasil = app(KepatuhanPajakService::class)->periksa($periode);
            $selain = $hasil['temuan']->where('kategori', '!=', KepatuhanPajakService::NPWP_PERUSAHAAN_TIDAK_VALID);

            $this->assertSame([], $selain->all(), "Data demo masa {$periode} harus lolos preflight.");
        }
    }
}
