<?php

namespace Tests\Feature;

use App\Models\Pelanggan;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class IdentitasPajakMasterTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        return User::factory()->create(['type' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
    }

    private function dataSupplier(array $ubah = []): array
    {
        return array_merge([
            'nama' => 'PT Identitas Uji ' . random_int(1000, 9999),
            'alamat' => 'Jl. Uji No. 2',
            'telp' => '0215550000',
            'pembayaran' => 'TRANSFER',
            'npwp' => '01.234.567.8-901.000',
            'nik' => '3174061502560010',
            'nitku' => '0012345678901000000000',
            'tipe_identitas' => 'NPWP',
            'status_pkp' => '1',
        ], $ubah);
    }

    private function dataPelanggan(array $ubah = []): array
    {
        return array_merge([
            'kode' => 'CUST-ID-' . random_int(1000, 9999),
            'nama' => 'PT Pembeli Identitas',
            'termin_hari' => 30,
            'npwp' => '0123456789012000',
            'nik' => '',
            'nitku' => '0123456789012000000000',
            'tipe_identitas' => 'NPWP',
            'status_pkp' => '0',
        ], $ubah);
    }

    public function test_a_supplier_is_stored_with_a_normalised_npwp_and_the_new_identity_fields(): void
    {
        $data = $this->dataSupplier();

        $this->actingAs($this->admin())->post(route('supplier.store'), $data)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('supplier.index'));

        $supplier = Supplier::where('nama', $data['nama'])->firstOrFail();

        $this->assertSame('0012345678901000', $supplier->npwp);
        $this->assertSame('3174061502560010', $supplier->nik);
        $this->assertSame('0012345678901000000000', $supplier->nitku);
        $this->assertSame('NPWP', $supplier->tipe_identitas);
        $this->assertTrue($supplier->status_pkp);
    }

    public function test_supplier_identity_fields_are_validated_on_create(): void
    {
        $admin = $this->admin();

        foreach ([
            'npwp' => '01234567890123',
            'nik' => '123',
            'nitku' => '123456789012345678901',
            'tipe_identitas' => 'PASPOR',
            'status_pkp' => 'mungkin',
        ] as $kolom => $nilai) {
            $data = $this->dataSupplier([$kolom => $nilai]);

            $this->actingAs($admin)->post(route('supplier.store'), $data)->assertSessionHasErrors($kolom);
            $this->assertNull(Supplier::where('nama', $data['nama'])->first(), "Supplier dengan {$kolom} tidak valid tidak boleh tersimpan.");
        }
    }

    public function test_a_supplier_can_be_updated_and_its_pkp_status_can_return_to_unknown(): void
    {
        $admin = $this->admin();
        $data = $this->dataSupplier();
        $this->actingAs($admin)->post(route('supplier.store'), $data);
        $supplier = Supplier::where('nama', $data['nama'])->firstOrFail();

        $this->actingAs($admin)->put(route('supplier.update', $supplier), $this->dataSupplier([
            'nama' => $data['nama'],
            'npwp' => '0923456789012000',
            'tipe_identitas' => 'NIK',
            'status_pkp' => '',
        ]))->assertSessionHasNoErrors();

        $supplier->refresh();
        $this->assertSame('0923456789012000', $supplier->npwp);
        $this->assertSame('NIK', $supplier->tipe_identitas);
        $this->assertNull($supplier->status_pkp);

        $this->actingAs($admin)->put(route('supplier.update', $supplier), $this->dataSupplier([
            'nama' => $data['nama'],
            'nik' => '99',
        ]))->assertSessionHasErrors('nik');

        $this->assertSame('3174061502560010', $supplier->fresh()->nik);
    }

    public function test_an_empty_identity_type_falls_back_to_npwp(): void
    {
        $data = $this->dataSupplier(['tipe_identitas' => '']);

        $this->actingAs($this->admin())->post(route('supplier.store'), $data)->assertSessionHasNoErrors();

        $this->assertSame('NPWP', Supplier::where('nama', $data['nama'])->firstOrFail()->tipe_identitas);
    }

    public function test_a_customer_is_stored_updated_and_validated_with_the_new_identity_fields(): void
    {
        $admin = $this->admin();
        $data = $this->dataPelanggan(['nik' => '3174061502560010', 'tipe_identitas' => 'NIK', 'status_pkp' => '']);

        $this->actingAs($admin)->post(route('pelanggan.store'), $data)->assertSessionHasNoErrors();

        $pelanggan = Pelanggan::where('kode', $data['kode'])->firstOrFail();
        $this->assertSame('NIK', $pelanggan->tipe_identitas);
        $this->assertSame('3174061502560010', $pelanggan->nik);
        $this->assertNull($pelanggan->status_pkp);

        $this->actingAs($admin)->put(route('pelanggan.update', $pelanggan), $this->dataPelanggan([
            'kode' => $pelanggan->kode,
            'status_pkp' => '1',
        ]))->assertSessionHasNoErrors();

        $this->assertTrue($pelanggan->fresh()->status_pkp);

        foreach (['npwp' => '12345', 'nik' => '12345', 'nitku' => '12345', 'tipe_identitas' => 'LAIN'] as $kolom => $nilai) {
            $this->actingAs($admin)->put(route('pelanggan.update', $pelanggan), $this->dataPelanggan([
                'kode' => $pelanggan->kode,
                $kolom => $nilai,
            ]))->assertSessionHasErrors($kolom);
        }
    }

    public function test_the_master_forms_render_the_identity_fields(): void
    {
        $admin = $this->admin();
        $pelanggan = Pelanggan::firstOrFail();
        $supplier = Supplier::firstOrFail();

        foreach ([
            route('pelanggan.create'),
            route('pelanggan.edit', $pelanggan),
            route('supplier.create'),
            route('supplier.edit', $supplier),
        ] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()
                ->assertSee('name="nik"', false)
                ->assertSee('name="nitku"', false)
                ->assertSee('name="tipe_identitas"', false)
                ->assertSee('name="status_pkp"', false);
        }
    }
}
