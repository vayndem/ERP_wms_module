<?php

namespace Tests\Feature;

use App\Models\AccountingSetting;
use App\Models\Bahan;
use App\Models\DataPesanan;
use App\Models\Gudang;
use App\Models\Pelanggan;
use App\Models\StokGudang;
use App\Models\JamKerjaProduksi;
use App\Models\JurnalDetail;
use App\Models\PusatKerja;
use App\Models\User;
use App\Services\AccountingReconciliationService;
use App\Services\DataPesananService;
use App\Services\PenjualanService;
use App\Services\SerapanProduksiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use RuntimeException;
use Tests\TestCase;

class SerapanTenagaKerjaTest extends TestCase
{
    use DatabaseTransactions;

    private function perintahKerja(): DataPesanan
    {
        $user = User::where('type', User::ROLE_SUPER_ADMIN)->firstOrFail();

        $stok = StokGudang::whereRaw('stok_tersedia - stok_direservasi >= 1')
            ->whereHas('gudang', fn ($query) => $query->where('jenis', Gudang::NORMAL))
            ->firstOrFail();

        $pelanggan = Pelanggan::create([
            'kode' => 'JKP-' . random_int(1000, 9999),
            'nama' => 'PT Pemesan Jam Kerja',
            'termin_hari' => 30,
            'plafon_kredit' => 0,
            'is_active' => true,
        ]);

        $produk = Bahan::whereKeyNot($stok->bahan_id)->firstOrFail();

        $pesanan = app(PenjualanService::class)->buatPesanan([
            'tanggal' => today()->toDateString(),
            'pelanggan_id' => $pelanggan->id,
            'gudang_id' => $stok->gudang_id,
            'is_ppn' => true,
            'tarif_ppn' => 11,
            'details' => [['bahan_id' => $produk->id, 'jumlah' => 5, 'harga_satuan' => 90000]],
        ], $user);

        $service = app(DataPesananService::class);

        $wo = $service->buat($pesanan->details->first(), [
            'tanggal' => today()->toDateString(),
            'gudang_id' => $stok->gudang_id,
            'jumlah_rencana' => 5,
        ], $user);

        return $service->rilis($wo);
    }

    private function pusatKerja(float $tarifTk = 50_000, float $tarifOh = 20_000): PusatKerja
    {
        $pusat = PusatKerja::where('status', PusatKerja::AKTIF)->firstOrFail();
        $pusat->update(['tarif_tenaga_kerja_per_jam' => $tarifTk, 'tarif_overhead_per_jam' => $tarifOh]);

        return $pusat->fresh();
    }

    public function test_recording_hours_absorbs_labour_and_overhead_into_work_in_process(): void
    {
        $produksi = User::where('type', User::ROLE_PRODUCTION)->firstOrFail();
        $pesanan = $this->perintahKerja();
        $pusat = $this->pusatKerja(50_000, 20_000);

        $wipSebelum = round((float) $pesanan->saldoWip(), 2);

        $jamKerja = app(SerapanProduksiService::class)->catat($pesanan, $pusat, [
            'tanggal' => today()->toDateString(),
            'jam' => 4,
            'keterangan' => 'Penjahitan batch pertama',
        ], $produksi);

        $this->assertEqualsWithDelta(200_000, (float) $jamKerja->biaya_tenaga_kerja, 0.01);
        $this->assertEqualsWithDelta(80_000, (float) $jamKerja->biaya_overhead, 0.01);

        $this->assertEqualsWithDelta(
            $wipSebelum + 280_000,
            round((float) $pesanan->fresh()->saldoWip(), 2),
            0.01,
            'Serapan jam kerja harus menambah WIP perintah kerjanya, bukan hanya membuat jurnal.'
        );
    }

    public function test_the_absorption_journal_debits_wip_and_credits_two_absorption_accounts(): void
    {
        $produksi = User::where('type', User::ROLE_PRODUCTION)->firstOrFail();
        $jamKerja = app(SerapanProduksiService::class)->catat($this->perintahKerja(), $this->pusatKerja(30_000, 10_000), [
            'tanggal' => today()->toDateString(),
            'jam' => 2,
        ], $produksi);

        $baris = JurnalDetail::where('jurnal_id', $jamKerja->journal_id)->get();

        $this->assertEqualsWithDelta(80_000,
            (float) $baris->where('coa_id', AccountingSetting::accountId(AccountingSetting::BARANG_DALAM_PROSES))->sum('debit'), 0.01);
        $this->assertEqualsWithDelta(60_000,
            (float) $baris->where('coa_id', AccountingSetting::accountId(AccountingSetting::BEBAN_TENAGA_KERJA_DISERAP))->sum('kredit'), 0.01);
        $this->assertEqualsWithDelta(20_000,
            (float) $baris->where('coa_id', AccountingSetting::accountId(AccountingSetting::BEBAN_OVERHEAD_DISERAP))->sum('kredit'), 0.01);

        $this->assertTrue(
            $baris->every(fn (JurnalDetail $d) => $d->gudang_id !== null),
            'Baris serapan wajib membawa dimensi gudang seperti posting produksi lainnya.'
        );
    }

    public function test_the_work_in_process_invariant_still_holds_after_absorption(): void
    {
        $produksi = User::where('type', User::ROLE_PRODUCTION)->firstOrFail();

        app(SerapanProduksiService::class)->catat($this->perintahKerja(), $this->pusatKerja(), [
            'tanggal' => today()->toDateString(),
            'jam' => 3,
        ], $produksi);

        $cek = app(AccountingReconciliationService::class)->checks()->firstWhere('key', 'wip');

        $this->assertSame(0, (int) $cek['invalid'],
            'Buku besar WIP dan subledger perintah kerja harus tetap sama setelah jam kerja diserap.');
    }

    public function test_a_work_centre_without_rates_refuses_to_absorb_anything(): void
    {
        $produksi = User::where('type', User::ROLE_PRODUCTION)->firstOrFail();
        $pusat = $this->pusatKerja(0, 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('belum punya tarif');

        app(SerapanProduksiService::class)->catat($this->perintahKerja(), $pusat, [
            'tanggal' => today()->toDateString(),
            'jam' => 5,
        ], $produksi);
    }

    public function test_a_sealed_work_order_refuses_further_hours(): void
    {
        $produksi = User::where('type', User::ROLE_PRODUCTION)->firstOrFail();
        $pesanan = $this->perintahKerja();
        $pusat = $this->pusatKerja();

        $pesanan->update(['status' => DataPesanan::SELESAI]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('tidak menerima biaya baru');

        app(SerapanProduksiService::class)->catat($pesanan->fresh(), $pusat, [
            'tanggal' => today()->toDateString(),
            'jam' => 1,
        ], $produksi);
    }

    public function test_withdrawing_an_entry_removes_both_the_journal_and_the_subledger_row(): void
    {
        $produksi = User::where('type', User::ROLE_PRODUCTION)->firstOrFail();
        $pesanan = $this->perintahKerja();
        $wipSebelum = round((float) $pesanan->saldoWip(), 2);

        $jamKerja = app(SerapanProduksiService::class)->catat($pesanan, $this->pusatKerja(), [
            'tanggal' => today()->toDateString(),
            'jam' => 2,
        ], $produksi);

        $jurnalId = $jamKerja->journal_id;

        app(SerapanProduksiService::class)->batalkan($jamKerja);

        $this->assertNull(JamKerjaProduksi::find($jamKerja->id));
        $this->assertSame(0, JurnalDetail::where('jurnal_id', $jurnalId)->count(), 'Jurnal serapan harus ikut hilang.');
        $this->assertEqualsWithDelta($wipSebelum, round((float) $pesanan->fresh()->saldoWip(), 2), 0.01);

        $cek = app(AccountingReconciliationService::class)->checks()->firstWhere('key', 'wip');
        $this->assertSame(0, (int) $cek['invalid']);
    }

    public function test_a_locked_period_refuses_to_withdraw_an_absorption(): void
    {
        $produksi = User::where('type', User::ROLE_PRODUCTION)->firstOrFail();
        $pesanan = $this->perintahKerja();

        $jamKerja = app(SerapanProduksiService::class)->catat($pesanan, $this->pusatKerja(), [
            'tanggal' => today()->toDateString(),
            'jam' => 2,
        ], $produksi);

        \App\Models\AccountingPeriodLock::create([
            'period_start' => today()->startOfMonth()->toDateString(),
            'period_end' => today()->endOfMonth()->toDateString(),
            'status' => 'LOCKED',
            'reason' => 'Uji kunci periode serapan',
            'locked_by' => User::firstOrFail()->id,
            'locked_at' => now(),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dikunci');

        app(SerapanProduksiService::class)->batalkan($jamKerja);
    }

    public function test_the_whole_path_works_over_http_and_is_closed_to_finance(): void
    {
        $produksi = User::where('type', User::ROLE_PRODUCTION)->firstOrFail();
        $pesanan = $this->perintahKerja();
        $pusat = $this->pusatKerja();

        $this->actingAs($produksi)
            ->post(route('data-pesanan.jam-kerja', $pesanan), [
                'tanggal' => today()->toDateString(),
                'pusat_kerja_id' => $pusat->id,
                'jam' => 1.5,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, JamKerjaProduksi::where('data_pesanan_id', $pesanan->id)->count());

        $finance = User::factory()->create(['type' => User::ROLE_FINANCE, 'is_active' => true]);

        $this->actingAs($finance)
            ->post(route('data-pesanan.jam-kerja', $pesanan), [
                'tanggal' => today()->toDateString(),
                'pusat_kerja_id' => $pusat->id,
                'jam' => 1,
            ])
            ->assertForbidden();
    }
}
