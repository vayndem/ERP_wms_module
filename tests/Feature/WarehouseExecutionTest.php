<?php

namespace Tests\Feature;

use App\Models\Bahan;
use App\Models\GelombangPengambilan;
use App\Models\Gudang;
use App\Models\Kit;
use App\Models\LayerPersediaan;
use App\Models\LokasiGudang;
use App\Models\PengaturanBahanGudang;
use App\Models\PerakitanKit;
use App\Models\StockOpname;
use App\Models\StokGudang;
use App\Models\User;
use App\Services\AbcAnalysisService;
use App\Services\CycleCountService;
use App\Services\GelombangPengambilanService;
use App\Services\KittingService;
use App\Services\WarehouseExecutionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Tests\TestCase;

class WarehouseExecutionTest extends TestCase
{
    use DatabaseTransactions;

    private function gudangUtama(): Gudang
    {
        return Gudang::where('jenis', Gudang::NORMAL)->where('aktif', true)->firstOrFail();
    }

    private function operator(array $gudangIds = []): User
    {
        $user = User::factory()->create(['type' => User::ROLE_WAREHOUSE]);

        foreach ($gudangIds as $gudangId) {
            $user->pembagianGudangs()->create([
                'gudang_id' => $gudangId,
                'boleh_menerima' => true,
                'boleh_npk' => true,
                'boleh_transfer' => true,
                'boleh_opname' => true,
            ]);
        }

        return $user->fresh();
    }

    public function test_warehouse_operator_only_sees_the_warehouses_they_are_assigned_to(): void
    {
        $gudangs = Gudang::where('aktif', true)->take(2)->get();
        $this->assertCount(2, $gudangs, 'Butuh minimal dua gudang aktif untuk menguji pembatasan.');

        $tanpaPenugasan = $this->operator();
        $this->assertSame([], $tanpaPenugasan->accessibleGudangIds());

        $terbatas = $this->operator([$gudangs[0]->id]);
        $this->assertSame([$gudangs[0]->id], $terbatas->accessibleGudangIds());
        $this->assertTrue($terbatas->canAccessGudang($gudangs[0]->id));
        $this->assertFalse($terbatas->canAccessGudang($gudangs[1]->id));

        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $this->assertGreaterThanOrEqual(2, count($admin->accessibleGudangIds()));
    }

    public function test_work_queue_is_empty_for_an_operator_without_warehouse_assignment(): void
    {
        $this->actingAs($this->operator())
            ->get(route('antrean-kerja.index'))
            ->assertOk()
            ->assertSee('belum ditugaskan ke gudang manapun', false);

        $this->actingAs($this->operator([$this->gudangUtama()->id]))
            ->get(route('antrean-kerja.index'))
            ->assertOk()
            ->assertSee('Tugas Menunggu', false);
    }

    public function test_abc_classification_puts_the_biggest_consumer_in_class_a(): void
    {
        $terhitung = app(AbcAnalysisService::class)->hitung();
        $this->assertGreaterThan(0, $terhitung, 'Data seed harus punya pemakaian NPK untuk diklasifikasi.');

        $teratas = PengaturanBahanGudang::whereNotNull('kelas_abc')
            ->orderByDesc('nilai_pemakaian')->first();

        $this->assertSame('A', $teratas->kelas_abc);
        $this->assertGreaterThan(0, (float) $teratas->nilai_pemakaian);

        $ulang = app(AbcAnalysisService::class)->hitung();
        $this->assertSame($terhitung, $ulang, 'Perhitungan ulang harus stabil, bukan menggandakan baris.');
    }

    public function test_cycle_count_only_seeds_materials_of_the_chosen_abc_class(): void
    {
        app(AbcAnalysisService::class)->hitung();

        $baris = PengaturanBahanGudang::whereNotNull('kelas_abc')
            ->whereHas('gudang', fn ($q) => $q->where('aktif', true))
            ->first();
        $this->assertNotNull($baris, 'Butuh minimal satu klasifikasi ABC.');

        StockOpname::where('warehouse_id', $baris->gudang_id)
            ->whereIn('status', [StockOpname::DRAFT, StockOpname::SUBMITTED, StockOpname::APPROVED, StockOpname::REJECTED])
            ->delete();

        Auth::login(User::factory()->create(['type' => User::ROLE_WAREHOUSE]));

        $opname = app(CycleCountService::class)->mulai(
            (int) $baris->gudang_id,
            $baris->kelas_abc,
            today()->toDateString(),
        );

        $this->assertSame(StockOpname::SIKLUS, $opname->jenis);
        $this->assertSame($baris->kelas_abc, $opname->kelas_abc);
        $this->assertGreaterThan(0, $opname->details->count());

        $kelasLain = PengaturanBahanGudang::where('gudang_id', $baris->gudang_id)
            ->where('kelas_abc', '!=', $baris->kelas_abc)
            ->pluck('bahan_id');

        $this->assertEmpty(
            $opname->details->pluck('bahan_id')->intersect($kelasLain),
            'Cycle count tidak boleh menyertakan bahan dari kelas ABC lain.',
        );
    }

    public function test_cycle_schedule_reports_days_since_the_last_posted_count_as_a_positive_number(): void
    {
        app(AbcAnalysisService::class)->hitung();

        $baris = PengaturanBahanGudang::whereNotNull('kelas_abc')
            ->where('aktif', true)
            ->whereHas('gudang', fn ($q) => $q->where('aktif', true))
            ->firstOrFail();

        $opname = StockOpname::where('warehouse_id', $baris->gudang_id)
            ->where('jenis', StockOpname::SIKLUS)
            ->where('kelas_abc', $baris->kelas_abc)
            ->where('status', StockOpname::POSTED);
        $this->assertSame(0, $opname->count(), 'Tes ini mengandaikan belum ada cycle count terposting untuk kelas ini.');

        $catat = fn (int $hari) => StockOpname::create([
            'number' => 'OPS-UJI-' . $hari . random_int(1000, 9999),
            'warehouse_id' => $baris->gudang_id,
            'jenis' => StockOpname::SIKLUS,
            'kelas_abc' => $baris->kelas_abc,
            'cutoff_at' => now()->subDays($hari),
            'status' => StockOpname::POSTED,
            'created_by' => User::factory()->create(['type' => User::ROLE_WAREHOUSE])->id,
            'posted_at' => now()->subDays($hari),
        ]);
        $papan = fn () => app(CycleCountService::class)->papanJadwal([(int) $baris->gudang_id])
            ->firstWhere('gudang_id', (int) $baris->gudang_id)['kelas'][$baris->kelas_abc];
        $abc = fn () => app(AbcAnalysisService::class)->jatuhTempoSiklus((int) $baris->gudang_id)[$baris->kelas_abc];

        $catat(10);
        $this->assertSame(10, $papan()['hari_sejak']);
        $this->assertSame(10, $abc()['hari_sejak']);
        $this->assertFalse($papan()['jatuh_tempo']);
        $this->assertFalse($abc()['jatuh_tempo']);

        StockOpname::where('warehouse_id', $baris->gudang_id)->where('number', 'like', 'OPS-UJI-10%')->delete();
        $catat(400);
        $this->assertSame(400, $papan()['hari_sejak']);
        $this->assertSame(400, $abc()['hari_sejak']);
        $this->assertTrue($papan()['jatuh_tempo']);
        $this->assertTrue($abc()['jatuh_tempo']);
    }

    public function test_cycle_count_refuses_an_unknown_class(): void
    {
        $this->expectException(RuntimeException::class);
        app(CycleCountService::class)->mulai($this->gudangUtama()->id, 'Z', today()->toDateString());
    }

    public function test_wave_groups_picks_and_refuses_one_already_in_another_wave(): void
    {
        $gudang = $this->gudangUtama();
        Auth::login($this->operator([$gudang->id]));

        $eksekusi = app(WarehouseExecutionService::class);
        $saldo = StokGudang::where('gudang_id', $gudang->id)->where('stok_tersedia', '>', 2)->firstOrFail();

        $picks = [];
        foreach ([1, 1] as $jumlah) {
            $reservasi = $eksekusi->reserve($gudang->id, (int) $saldo->bahan_id, $jumlah);
            $picks[] = $eksekusi->createPick($reservasi);
        }

        $service = app(GelombangPengambilanService::class);
        $gelombang = $service->buat($gudang->id, collect($picks)->pluck('id')->all(), 'LOKASI');

        $this->assertSame(GelombangPengambilan::DIRENCANAKAN, $gelombang->status);
        $this->assertSame(2, $gelombang->pesanan()->count());
        $this->assertSame([1, 2], $gelombang->pesanan()->pluck('urutan_dalam_gelombang')->map(fn ($n) => (int) $n)->all());

        $this->expectException(RuntimeException::class);
        $service->buat($gudang->id, [$picks[0]->id], 'LOKASI');
    }

    public function test_wave_lifecycle_refuses_completion_while_a_pick_is_open(): void
    {
        $gudang = $this->gudangUtama();
        Auth::login($this->operator([$gudang->id]));

        $eksekusi = app(WarehouseExecutionService::class);
        $saldo = StokGudang::where('gudang_id', $gudang->id)->where('stok_tersedia', '>', 1)->firstOrFail();
        $pick = $eksekusi->createPick($eksekusi->reserve($gudang->id, (int) $saldo->bahan_id, 1));

        $service = app(GelombangPengambilanService::class);
        $gelombang = $service->buat($gudang->id, [$pick->id], 'BAHAN');
        $service->rilis($gelombang->fresh());

        $this->assertSame(GelombangPengambilan::DIRILIS, $gelombang->fresh()->status);

        try {
            $service->selesaikan($gelombang->fresh());
            $this->fail('Gelombang dengan perintah belum selesai seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('belum selesai', $e->getMessage());
        }

        $service->batalkan($gelombang->fresh());
        $this->assertSame(GelombangPengambilan::DIBATALKAN, $gelombang->fresh()->status);
        $this->assertNull($pick->fresh()->gelombang_id, 'Pembatalan harus melepas perintah pengambilan.');
    }

    public function test_kit_assembly_consumes_components_and_posts_a_balanced_journal(): void
    {
        $gudang = $this->gudangUtama();
        Auth::login(User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]));

        $saldo = StokGudang::where('gudang_id', $gudang->id)->where('stok_tersedia', '>', 2)->take(2)->get();
        $this->assertCount(2, $saldo, 'Butuh dua bahan bersaldo untuk merakit kit.');

        $hasil = Bahan::whereNotIn('id', $saldo->pluck('bahan_id'))->whereNotNull('tipe_barang')->firstOrFail();

        $kit = Kit::create([
            'kode' => 'KIT-TEST-' . now()->format('His'),
            'nama' => 'Kit Pengujian',
            'bahan_hasil_id' => $hasil->id,
            'jumlah_hasil' => 1,
            'aktif' => true,
        ]);

        foreach ($saldo as $row) {
            $kit->komponen()->create(['bahan_id' => $row->bahan_id, 'jumlah' => 1]);
        }

        $sebelum = $saldo->mapWithKeys(fn ($row) => [$row->bahan_id => (float) $row->stok_tersedia]);

        $perakitan = app(KittingService::class)->rakit($kit, $gudang->id, 1, today()->toDateString());

        $this->assertSame(PerakitanKit::POSTED, $perakitan->status);
        $this->assertGreaterThan(0, (float) $perakitan->nilai_total);
        $this->assertSame(2, $perakitan->details->count());

        foreach ($saldo as $row) {
            $sesudah = (float) StokGudang::where('gudang_id', $gudang->id)->where('bahan_id', $row->bahan_id)->value('stok_tersedia');
            $this->assertEqualsWithDelta($sebelum[$row->bahan_id] - 1, $sesudah, 0.000001);
        }

        $layerKit = LayerPersediaan::where('source_type', 'PERAKITAN_KIT')->where('source_id', $perakitan->id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $perakitan->nilai_total, (float) $layerKit->initial_quantity * (float) $layerKit->unit_cost, 0.05);

        if ($perakitan->journal) {
            $details = $perakitan->journal->details;
            $this->assertEqualsWithDelta((float) $details->sum('debit'), (float) $details->sum('kredit'), 0.01);
        }
    }

    public function test_kit_refuses_assembly_when_a_component_has_no_stock(): void
    {
        $gudang = $this->gudangUtama();
        Auth::login(User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]));

        $kosong = Bahan::whereDoesntHave('stokGudangs', fn ($q) => $q->where('gudang_id', $gudang->id)->where('stok_tersedia', '>', 0))
            ->whereNotNull('tipe_barang')->first();

        if (!$kosong) {
            $this->markTestSkipped('Semua bahan punya stok di gudang ini.');
        }

        $hasil = Bahan::where('id', '!=', $kosong->id)->whereNotNull('tipe_barang')->firstOrFail();

        $kit = Kit::create([
            'kode' => 'KIT-KOSONG-' . now()->format('His'),
            'nama' => 'Kit Tanpa Stok',
            'bahan_hasil_id' => $hasil->id,
            'jumlah_hasil' => 1,
            'aktif' => true,
        ]);
        $kit->komponen()->create(['bahan_id' => $kosong->id, 'jumlah' => 5]);

        $ketersediaan = app(KittingService::class)->ketersediaan($kit, $gudang->id, 1);
        $this->assertFalse($ketersediaan[0]['cukup']);

        $this->expectException(RuntimeException::class);
        app(KittingService::class)->rakit($kit, $gudang->id, 1, today()->toDateString());
    }

    public function test_bin_rejects_a_putaway_that_exceeds_its_volume(): void
    {
        $gudang = $this->gudangUtama();

        $bahan = Bahan::whereNotNull('tipe_barang')->firstOrFail();
        $bahan->update(['volume_cm3' => 1000]);

        $lokasi = LokasiGudang::create([
            'gudang_id' => $gudang->id,
            'code' => 'UJI-VOL-' . now()->format('His'),
            'name' => 'Bin Uji Volume',
            'type' => 'STORAGE',
            'active' => true,
            'panjang_cm' => 10,
            'lebar_cm' => 10,
            'tinggi_cm' => 10,
        ]);

        $this->assertEqualsWithDelta(1000.0, $lokasi->kapasitasVolume(), 0.01);

        $lokasi->assertMuat(1, $bahan);

        $this->expectException(RuntimeException::class);
        $lokasi->assertMuat(2, $bahan);
    }

    private function buatLpbUntukQc(User $warehouse, string $tanda): \App\Models\PenerimaanBarang
    {
        $purchasing = User::factory()->create(['type' => User::ROLE_PURCHASING]);
        $numbers = app(\App\Services\DocumentNumberService::class);
        $gudang = Gudang::where('nama', 'Gudang Utama')->firstOrFail();
        $bahans = Bahan::whereNotNull('kategori')->take(2)->get();
        $supplier = \App\Models\Supplier::create([
            'nama' => "Supplier {$tanda}", 'alamat' => 'Jl. QC', 'telp' => '08110000' . random_int(10, 99), 'pembayaran' => 'Transfer',
        ]);

        $poNumber = $this->actingAs($purchasing)->postJson(route('pembelian.store'), [
            'tanggal' => today()->toDateString(),
            'supplier_id' => $supplier->id,
            'gudang_id' => $gudang->id,
            'is_ppn' => 0,
            'details' => $bahans->map(fn ($b) => ['bahan_id' => $b->id, 'harga' => 5000, 'jumlah' => 4])->all(),
        ])->assertCreated()->json('data.no_po');

        $noLpb = $this->actingAs($warehouse)->postJson(route('penerimaan-barang.store'), [
            'tanggal' => today()->toDateString(),
            'no_po' => $poNumber,
            'no_sj' => "SJ-{$tanda}",
            'details' => $bahans->map(fn ($b) => [
                'id_bahan' => $b->id, 'id_kategori' => $b->kategori, 'jumlah_barang_diterima' => 4,
            ])->all(),
        ])->assertCreated()->json('data.id_lpb');

        return \App\Models\PenerimaanBarang::with('details')->where('id_lpb', $noLpb)->firstOrFail();
    }

    public function test_follow_up_qc_is_allowed_after_a_hold_but_must_name_the_lines(): void
    {
        $gudang = $this->gudangUtama();
        $operator = $this->operator([$gudang->id]);
        Auth::login($operator);

        $lpb = $this->buatLpbUntukQc($operator, 'QC' . now()->format('His'));
        Auth::login($operator);

        $eksekusi = app(WarehouseExecutionService::class);
        $baris = $lpb->details;

        $eksekusi->inspect($lpb, [$baris[0]->id => ['accepted' => 3, 'reason' => 'penyok']]);

        $lpb->refresh();
        $this->assertSame('QC_COMPLETED_WITH_HOLD', $lpb->receiving_status);

        try {
            $eksekusi->inspect($lpb, []);
            $this->fail('QC lanjutan tanpa menyebut baris seharusnya ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('menyebutkan baris', $e->getMessage());
        }

        $lanjutan = $eksekusi->inspect($lpb, [$baris[1]->id => ['accepted' => 4]]);

        $this->assertNotNull($lanjutan->id);
        $this->assertSame(1, $lanjutan->lines()->count(), 'QC lanjutan hanya mencatat baris yang disebut.');
        $this->assertSame('QC_COMPLETED_WITH_HOLD', $lpb->fresh()->receiving_status,
            'Selama masih ada stok QC hold, status penerimaan tidak boleh dianggap bersih.');
    }

    public function test_material_request_approval_locks_available_stock_partially(): void
    {
        $gudang = $this->gudangUtama();
        Auth::login(User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]));

        $saldo = StokGudang::where('gudang_id', $gudang->id)->where('stok_tersedia', '>', 1)->firstOrFail();
        $bebas = (float) $saldo->stok_tersedia - (float) $saldo->stok_direservasi;

        $request = \App\Models\MaterialRequest::create([
            'no_request' => 'REQ-UJI-' . now()->format('His'),
            'status' => \App\Models\MaterialRequest::APPROVED,
            'requested_by' => Auth::id(),
        ]);
        $request->details()->create([
            'bahan_id' => $saldo->bahan_id,
            'nama_barang' => 'Uji reservasi otomatis',
            'jumlah_minta' => $bebas + 10,
            'jumlah_acc' => $bebas + 10,
            'realisasi' => 0,
            'tipe_gudang' => $gudang->id,
        ]);

        $hasil = app(WarehouseExecutionService::class)->reservasiOtomatis($request->fresh('details'));

        $this->assertSame(1, $hasil['sebagian'], 'Stok kurang harus dikunci sebagian, bukan gagal total.');
        $this->assertEqualsWithDelta($bebas, $hasil['rincian'][0]['dikunci'], 0.000001);

        $sesudah = StokGudang::where('gudang_id', $gudang->id)->where('bahan_id', $saldo->bahan_id)->firstOrFail();
        $this->assertEqualsWithDelta((float) $saldo->stok_direservasi + $bebas, (float) $sesudah->stok_direservasi, 0.000001);
    }
}
