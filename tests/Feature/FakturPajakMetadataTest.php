<?php

namespace Tests\Feature;

use App\Http\Requests\StoreFakturPembelianRequest;
use App\Http\Requests\UpdateFakturPembelianRequest;
use App\Models\FakturPembelian;
use App\Models\FakturPenjualan;
use App\Models\Gudang;
use App\Models\KodeObjekPajak;
use App\Models\Pelanggan;
use App\Models\PesananPenjualan;
use App\Models\StokGudang;
use App\Models\SuratJalan;
use App\Models\User;
use App\Services\FakturPenjualanService;
use App\Services\PenjualanService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class FakturPajakMetadataTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::factory()->create(['type' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
    }

    private function pelangganBerIdentitas(): Pelanggan
    {
        return Pelanggan::create([
            'kode' => 'CUST-SNAP-' . random_int(1000, 9999),
            'nama' => 'PT Snapshot Awal',
            'npwp' => '0123456789012000',
            'nik' => '3174061502560010',
            'nitku' => '0123456789012000000000',
            'tipe_identitas' => 'NPWP',
            'status_pkp' => true,
            'alamat' => 'Jl. Awal No. 1',
            'termin_hari' => 30,
            'is_active' => true,
        ]);
    }

    private function suratJalanTerposting(User $user, ?Pelanggan $pelanggan = null): SuratJalan
    {
        $stok = StokGudang::whereRaw('stok_tersedia - stok_direservasi >= 3')
            ->whereHas('gudang', fn ($query) => $query->where('jenis', Gudang::NORMAL))
            ->firstOrFail();

        $service = app(PenjualanService::class);
        $pesanan = $service->buatPesanan([
            'tanggal' => today()->toDateString(),
            'pelanggan_id' => ($pelanggan ?? $this->pelangganBerIdentitas())->id,
            'gudang_id' => $stok->gudang_id,
            'is_ppn' => true,
            'tarif_ppn' => 11,
            'details' => [['bahan_id' => $stok->bahan_id, 'jumlah' => 1, 'harga_satuan' => 50000]],
        ], $user);

        $suratJalan = $service->buatSuratJalan($pesanan, [
            'tanggal' => today()->toDateString(),
            'details' => [['pesanan_penjualan_detail_id' => $pesanan->details->first()->id, 'jumlah' => 1]],
        ], $user);

        return $service->postingSuratJalan($suratJalan, $user);
    }

    public function test_the_buyer_snapshot_is_taken_at_posting_and_survives_later_master_edits(): void
    {
        $admin = $this->admin();
        $pelanggan = $this->pelangganBerIdentitas();
        $suratJalan = $this->suratJalanTerposting($admin, $pelanggan);
        $service = app(FakturPenjualanService::class);

        $faktur = $service->buatDariSuratJalan($suratJalan, [
            'tanggal' => today()->toDateString(),
            'no_faktur_pajak' => '04002600000201001',
        ], $admin);

        $this->assertNull($faktur->pembeli_nama, 'Draft belum boleh membawa snapshot.');

        $faktur = $service->posting($faktur, $admin);

        $this->assertSame('PT Snapshot Awal', $faktur->pembeli_nama);
        $this->assertSame('0123456789012000', $faktur->pembeli_npwp);
        $this->assertSame('3174061502560010', $faktur->pembeli_nik);
        $this->assertSame('0123456789012000000000', $faktur->pembeli_nitku);
        $this->assertSame('Jl. Awal No. 1', $faktur->pembeli_alamat);

        $pelanggan->update([
            'nama' => 'PT Nama Baru',
            'npwp' => '0999999999999000',
            'nik' => null,
            'nitku' => null,
            'alamat' => 'Jl. Pindah No. 9',
        ]);

        $faktur = $faktur->fresh();

        $this->assertSame('PT Snapshot Awal', $faktur->pembeli_nama);
        $this->assertSame('0123456789012000', $faktur->pembeli_npwp);
        $this->assertSame('3174061502560010', $faktur->pembeli_nik);
        $this->assertSame('0123456789012000000000', $faktur->pembeli_nitku);
        $this->assertSame('Jl. Awal No. 1', $faktur->pembeli_alamat);
    }

    public function test_tax_invoice_date_and_ppnbm_are_stored_over_http_and_ppnbm_does_not_touch_totals(): void
    {
        $admin = $this->admin();
        $suratJalan = $this->suratJalanTerposting($admin);

        $this->actingAs($admin)->post(route('surat-jalan.faktur', $suratJalan), [
            'tanggal' => today()->toDateString(),
            'no_faktur_pajak' => '04002600000301001',
            'tanggal_faktur_pajak' => '2026-11-18',
            'ppnbm' => '1500.50',
        ])->assertSessionHasNoErrors();

        $faktur = FakturPenjualan::latest('id')->firstOrFail();

        $this->assertSame('2026-11-18', $faktur->tanggal_faktur_pajak->toDateString());
        $this->assertSame('1500.50', $faktur->ppnbm);
        $this->assertEqualsWithDelta(50000, (float) $faktur->total_dpp, 0.01);
        $this->assertEqualsWithDelta(5500, (float) $faktur->total_ppn, 0.01);
        $this->assertEqualsWithDelta(55500, (float) $faktur->grand_total, 0.01);
    }

    public function test_sales_nsfp_accepts_seventeen_digits_and_the_old_format_and_rejects_anything_else(): void
    {
        $admin = $this->admin();
        $suratJalan = $this->suratJalanTerposting($admin);

        foreach (['0400260000040100', '040026000004010011', '04-00-26000004010', '010.000-26.0000040'] as $salah) {
            $this->actingAs($admin)->post(route('surat-jalan.faktur', $suratJalan), [
                'tanggal' => today()->toDateString(),
                'no_faktur_pajak' => $salah,
            ])->assertSessionHasErrors('no_faktur_pajak');
        }

        $this->assertSame(0, FakturPenjualan::where('no_faktur_pajak', 'like', '0400260000040%')->count());

        $this->actingAs($admin)->post(route('surat-jalan.faktur', $suratJalan), [
            'tanggal' => today()->toDateString(),
            'no_faktur_pajak' => '010.000-26.00000411',
        ])->assertSessionHasNoErrors();

        $this->assertSame('010.000-26.00000411', FakturPenjualan::latest('id')->firstOrFail()->no_faktur_pajak);
    }

    public function test_a_sales_nsfp_cannot_be_reused_by_a_second_invoice(): void
    {
        $admin = $this->admin();
        $dipakai = FakturPenjualan::whereNotNull('no_faktur_pajak')->firstOrFail()->no_faktur_pajak;
        $suratJalan = $this->suratJalanTerposting($admin);

        $this->actingAs($admin)->post(route('surat-jalan.faktur', $suratJalan), [
            'tanggal' => today()->toDateString(),
            'no_faktur_pajak' => $dipakai,
        ])->assertSessionHasErrors('no_faktur_pajak');

        $this->assertSame(1, FakturPenjualan::where('no_faktur_pajak', $dipakai)->count());
    }

    public function test_the_database_itself_refuses_a_duplicate_sales_nsfp_but_allows_many_empty_ones(): void
    {
        $admin = $this->admin();
        $dipakai = FakturPenjualan::whereNotNull('no_faktur_pajak')->firstOrFail();

        $tanpaNomorSatu = $dipakai->replicate(['nomor', 'no_faktur_pajak']);
        $tanpaNomorSatu->nomor = 'FJL-UJI-1';
        $tanpaNomorSatu->no_faktur_pajak = null;
        $tanpaNomorSatu->save();

        $tanpaNomorDua = $dipakai->replicate(['nomor', 'no_faktur_pajak']);
        $tanpaNomorDua->nomor = 'FJL-UJI-2';
        $tanpaNomorDua->no_faktur_pajak = null;
        $tanpaNomorDua->save();

        $this->assertNotNull($tanpaNomorDua->id);

        $kembar = $dipakai->replicate(['nomor']);
        $kembar->nomor = 'FJL-UJI-3';

        $this->expectException(QueryException::class);
        $kembar->save();
    }

    public function test_purchase_invoice_requests_validate_nsfp_and_object_code_against_the_withholding_type(): void
    {
        foreach ([StoreFakturPembelianRequest::class, UpdateFakturPembelianRequest::class] as $kelas) {
            $galat = function (array $data) use ($kelas) {
                $request = $kelas::create('/', 'POST', $data);

                return Validator::make($data, $request->rules(), $request->messages())->errors();
            };

            $dasar = ['is_ppn' => 1];

            $this->assertTrue($galat($dasar + ['no_faktur_pajak' => '04002600000501001'])->missing('no_faktur_pajak'));
            $this->assertTrue($galat($dasar + ['no_faktur_pajak' => '010.001-23.12345678'])->missing('no_faktur_pajak'));
            $this->assertTrue($galat($dasar + ['no_faktur_pajak' => '0400260000050100'])->has('no_faktur_pajak'));

            $this->assertTrue($galat(['jenis_pph' => 'PPH23', 'kode_objek_pajak' => '24-104-29'])->missing('kode_objek_pajak'));
            $this->assertTrue($galat(['jenis_pph' => 'PPH4A2', 'kode_objek_pajak' => '28-403-02'])->missing('kode_objek_pajak'));
            $this->assertTrue($galat(['jenis_pph' => 'PPH4A2', 'kode_objek_pajak' => '24-104-29'])->has('kode_objek_pajak'));
            $this->assertTrue($galat(['kode_objek_pajak' => '24-104-29'])->has('kode_objek_pajak'));
            $this->assertTrue($galat(['jenis_pph' => 'PPH23', 'kode_objek_pajak' => '99-999-99'])->has('kode_objek_pajak'));

            $this->assertTrue($galat(['tanggal_faktur_pajak' => 'bukan-tanggal'])->has('tanggal_faktur_pajak'));
            $this->assertTrue($galat(['ppnbm' => '-1'])->has('ppnbm'));
            $this->assertTrue($galat(['ppnbm' => '2500000.75'])->missing('ppnbm'));
        }
    }

    public function test_object_code_reference_holds_only_the_verified_codes_and_no_rates(): void
    {
        $kode = KodeObjekPajak::orderBy('kode')->get(['kode', 'jenis_pph'])->mapWithKeys(fn ($baris) => [$baris->kode => $baris->jenis_pph]);

        $this->assertSame([
            '24-100-02' => 'PPH23',
            '24-104-29' => 'PPH23',
            '28-403-02' => 'PPH4A2',
        ], $kode->all());

        $kolom = \Illuminate\Support\Facades\Schema::getColumnListing('wms_kode_objek_pajak');
        foreach ($kolom as $nama) {
            $this->assertDoesNotMatchRegularExpression('/tarif|rate/i', $nama, 'Tarif tetap dibaca dari tax_rates, bukan disimpan ganda.');
        }
    }

    public function test_purchase_invoice_forms_render_the_new_tax_fields(): void
    {
        $admin = $this->admin();
        $faktur = FakturPembelian::firstOrFail();

        $this->actingAs($admin)->get(route('faktur-pembelian.create'))->assertOk()
            ->assertSee('kode_objek_pajak', false)
            ->assertSee('tanggal_faktur_pajak', false)
            ->assertSee('24-104-29', false);

        $this->actingAs($admin)->get(route('faktur-pembelian.edit', $faktur->id))->assertOk()
            ->assertSee('name="kode_objek_pajak"', false)
            ->assertSee('name="tanggal_faktur_pajak"', false)
            ->assertSee('name="ppnbm"', false);
    }

    public function test_posted_sales_invoices_carry_a_buyer_snapshot(): void
    {
        $faktur = FakturPenjualan::whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])->firstOrFail();

        $this->assertNotNull($faktur->pembeli_nama);
    }
}
