<?php

namespace Tests\Feature;

use App\Models\BaganAkun;
use App\Models\BuktiPotong;
use App\Models\FakturPembelian;
use App\Models\KodeObjekPajak;
use App\Models\LampiranDokumen;
use App\Models\PembayaranFaktur;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BuktiPotongExportService;
use App\Services\BuktiPotongService;
use App\Services\KepatuhanPajakService;
use App\Services\LaporanPajakDjpService;
use App\Services\WmsAccountingService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class BuktiPotongTest extends TestCase
{
    use DatabaseTransactions;

    private const MASA_1 = '2032-03';
    private const MASA_2 = '2032-04';

    private int $urutan = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.npwp_perusahaan' => '0123456789012000',
            'app.nitku_perusahaan' => '0123456789012000000000',
        ]);
    }

    private function finance(): User
    {
        return User::where('type', User::ROLE_FINANCE)->firstOrFail();
    }

    private function supplier(array $ubah = []): Supplier
    {
        return Supplier::create(array_merge([
            'nama' => 'PT Bukti Potong ' . ++$this->urutan . random_int(100, 999),
            'alamat' => 'Jl. Uji Potong',
            'telp' => '021',
            'pembayaran' => 'TRANSFER',
            'npwp' => '0123456789012002',
            'nitku' => '0123456789012002000000',
            'tipe_identitas' => 'NPWP',
        ], $ubah));
    }

    private function faktur(Supplier $supplier, array $ubah = []): FakturPembelian
    {
        return FakturPembelian::create(array_merge([
            'no_invoice' => 'INV-BP-' . ++$this->urutan . random_int(1000, 9999),
            'kode_supplier' => $supplier->id,
            'tanggal' => self::MASA_1 . '-01',
            'sub_total' => 10000000,
            'jenis_pajak' => 'NON_PPN',
            'dpp_ppn' => 0,
            'tarif_ppn' => 0,
            'ppn' => 0,
            'ppn_impor' => 0,
            'jenis_pph' => 'PPH23',
            'kode_objek_pajak' => '24-104-29',
            'dasar_pph' => 10000000,
            'tarif_pph' => 2,
            'diskon' => 0,
            'ongkir' => 0,
            'pph' => 0,
            'grand_total' => 10000000,
            'total_pembayaran' => 0,
            'sisa_tagihan' => 10000000,
            'match_status' => 'MATCHED',
            'status' => FakturPembelian::UNPAID,
        ], $ubah));
    }

    private function bayar(FakturPembelian $faktur, string $tanggal, float $pph, bool $jurnal = false): PembayaranFaktur
    {
        $bank = BaganAkun::where('kode_akun', '1102')->firstOrFail();

        $pembayaran = PembayaranFaktur::create([
            'payment_number' => 'PY-BP-' . ++$this->urutan . random_int(1000, 9999),
            'invoice_lpb_id' => $faktur->id,
            'supplier_id' => $faktur->kode_supplier,
            'tanggal_pembayaran' => $tanggal,
            'metode_pembayaran' => 'Transfer',
            'coa_kas_bank_id' => $bank->id,
            'jumlah_pembayaran' => 4900000,
            'potongan_pph' => $pph,
            'potongan_materai' => 0,
            'biaya_transfer_bank' => 0,
            'selisih_bayar' => 0,
            'kelebihan_pembayaran' => 0,
            'uang_muka_dipakai' => 0,
            'total_transaksi_pengurang_hutang' => 4900000 + $pph,
            'finance_user_id' => $this->finance()->id,
        ]);

        $faktur->update(['pph' => $faktur->payments()->sum('potongan_pph')]);

        if ($jurnal) {
            $this->actingAs($this->finance());
            app(WmsAccountingService::class)->postPayment($pembayaran);
        }

        return $pembayaran;
    }

    private function batalkanPembayaran(PembayaranFaktur $pembayaran, bool $denganJurnal = false): void
    {
        if ($denganJurnal) {
            $this->actingAs($this->finance());
            app(WmsAccountingService::class)->reverseAutomaticJournal('PELUNASAN_HUTANG', $pembayaran->id, 'Uji pembatalan');
        }

        $pembayaran->update(['status' => PembayaranFaktur::VOID]);
        $pembayaran->invoice->update(['pph' => $pembayaran->invoice->payments()->sum('potongan_pph')]);
    }

    private function service(): BuktiPotongService
    {
        return app(BuktiPotongService::class);
    }

    private function duaPembayaranDiDuaMasa(): array
    {
        $supplier = $this->supplier();
        $faktur = $this->faktur($supplier);
        $a = $this->bayar($faktur, self::MASA_1 . '-15', 100000);
        $b = $this->bayar($faktur, self::MASA_2 . '-10', 100000);

        return [$supplier, $faktur, $a, $b];
    }

    public function test_one_invoice_paid_in_two_months_produces_one_withholding_per_payment_in_the_payment_month(): void
    {
        [, $faktur, $a, $b] = $this->duaPembayaranDiDuaMasa();
        $laporan = app(LaporanPajakDjpService::class);

        $masa1 = $laporan->buktiPotong(self::MASA_1);
        $masa2 = $laporan->buktiPotong(self::MASA_2);

        $this->assertCount(1, $masa1->where('nomor_invoice', $faktur->no_invoice), 'Masa pertama harus memuat pembayaran pertama.');
        $this->assertCount(1, $masa2->where('nomor_invoice', $faktur->no_invoice), 'Masa kedua harus memuat pembayaran kedua; sumbernya pembayaran, bukan tanggal faktur.');
        $this->assertSame(100000.0, $masa1->firstWhere('nomor_pembayaran', $a->payment_number)['pph_dipotong'], 'PPh per baris adalah potongan pembayaran itu, bukan agregat faktur (200000).');
        $this->assertSame(100000.0, $masa2->firstWhere('nomor_pembayaran', $b->payment_number)['pph_dipotong']);
        $this->assertSame(5000000.0, $masa1->firstWhere('nomor_pembayaran', $a->payment_number)['dasar_pengenaan']);

        $this->service()->sinkron(self::MASA_1, $this->finance());
        $this->service()->sinkron(self::MASA_2, $this->finance());

        $this->assertSame(
            [3, 4],
            BuktiPotong::whereIn('pembayaran_id', [$a->id, $b->id])->orderBy('pembayaran_id')->pluck('masa_pajak')->all()
        );
    }

    public function test_a_voided_payment_disappears_from_the_withholding_dataset(): void
    {
        [, $faktur, $a, $b] = $this->duaPembayaranDiDuaMasa();
        $this->batalkanPembayaran($b);

        $this->assertCount(0, app(LaporanPajakDjpService::class)->buktiPotong(self::MASA_2)->where('nomor_invoice', $faktur->no_invoice));
        $this->assertCount(1, app(LaporanPajakDjpService::class)->buktiPotong(self::MASA_1)->where('nomor_invoice', $faktur->no_invoice));
    }

    public function test_sync_is_idempotent(): void
    {
        [, , $a] = $this->duaPembayaranDiDuaMasa();

        $pertama = $this->service()->sinkron(self::MASA_1, $this->finance());
        $kedua = $this->service()->sinkron(self::MASA_1, $this->finance());

        $this->assertSame(1, $pertama['dibuat']);
        $this->assertSame(0, $kedua['dibuat']);
        $this->assertSame(0, $kedua['diperbarui']);
        $this->assertSame(1, BuktiPotong::where('pembayaran_id', $a->id)->count());
    }

    public function test_the_database_refuses_a_second_row_for_the_same_payment_version(): void
    {
        [, , $a] = $this->duaPembayaranDiDuaMasa();
        $this->service()->sinkron(self::MASA_1, $this->finance());
        $baris = BuktiPotong::where('pembayaran_id', $a->id)->firstOrFail();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('wms_bukti_potong')->insert(
            collect($baris->getAttributes())->except('id')->all()
        );
    }

    public function test_a_draft_is_cancelled_and_an_issued_one_is_flagged_when_its_payment_is_voided(): void
    {
        $supplier = $this->supplier();
        $faktur = $this->faktur($supplier);
        $draft = $this->bayar($faktur, self::MASA_1 . '-05', 100000);
        $terbit = $this->bayar($faktur, self::MASA_1 . '-20', 100000);

        $this->service()->sinkron(self::MASA_1, $this->finance());
        $buktiTerbit = BuktiPotong::where('pembayaran_id', $terbit->id)->firstOrFail();
        $this->service()->terbitkan($buktiTerbit, 'NOMOR-UJI-1', self::MASA_1 . '-21', $this->finance());

        $this->batalkanPembayaran($draft);
        $this->batalkanPembayaran($terbit);
        $hasil = $this->service()->sinkron(self::MASA_1, $this->finance());

        $this->assertSame(1, $hasil['dibatalkan']);
        $this->assertSame(1, $hasil['ditandai']);
        $this->assertSame(BuktiPotong::DIBATALKAN, BuktiPotong::where('pembayaran_id', $draft->id)->value('status'));

        $buktiTerbit->refresh();
        $this->assertSame(BuktiPotong::DITERBITKAN, $buktiTerbit->status, 'Dokumen terbit tidak diubah diam-diam.');
        $this->assertTrue($buktiTerbit->perlu_pembatalan);

        $ringkasan = app(KepatuhanPajakService::class)->periksa(self::MASA_1)['ringkasan'];
        $this->assertSame(1, $ringkasan[KepatuhanPajakService::BUKTI_POTONG_PEMBAYARAN_VOID]);

        $this->service()->batalkan($buktiTerbit, 'Pembayaran dibatalkan', $this->finance());
        $buktiTerbit->refresh();
        $this->assertSame(BuktiPotong::DIBATALKAN, $buktiTerbit->status);
        $this->assertFalse($buktiTerbit->perlu_pembatalan);
    }

    public function test_issuing_refuses_incomplete_identity_missing_or_mismatched_object_code_and_duplicate_numbers(): void
    {
        $tanpaNitku = $this->supplier(['nitku' => null]);
        $fakturA = $this->faktur($tanpaNitku);
        $this->bayar($fakturA, self::MASA_1 . '-10', 100000);

        $tanpaKode = $this->supplier();
        $fakturB = $this->faktur($tanpaKode, ['kode_objek_pajak' => null]);
        $this->bayar($fakturB, self::MASA_1 . '-11', 100000);

        $kodeSalah = $this->supplier();
        $fakturC = $this->faktur($kodeSalah, ['kode_objek_pajak' => '28-403-02']);
        $this->bayar($fakturC, self::MASA_1 . '-12', 100000);

        $baik = $this->supplier();
        $fakturD = $this->faktur($baik);
        $this->bayar($fakturD, self::MASA_1 . '-13', 100000);
        $fakturE = $this->faktur($baik);
        $this->bayar($fakturE, self::MASA_1 . '-14', 100000);

        $this->service()->sinkron(self::MASA_1, $this->finance());
        $bukti = fn (FakturPembelian $faktur) => BuktiPotong::whereHas('pembayaran', fn ($q) => $q->where('invoice_lpb_id', $faktur->id))->firstOrFail();

        foreach ([[$fakturA, 'NITKU'], [$fakturB, 'Kode objek pajak belum diisi'], [$fakturC, 'milik PPH4A2']] as [$faktur, $kata]) {
            try {
                $this->service()->terbitkan($bukti($faktur), 'NOMOR-TOLAK', null, $this->finance());
                $this->fail('Penerbitan seharusnya ditolak: ' . $kata);
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($kata, $e->getMessage());
            }

            $this->assertSame(BuktiPotong::DRAFT, $bukti($faktur)->status);
        }

        $this->service()->terbitkan($bukti($fakturD), 'NOMOR-UJI-DUP', null, $this->finance());
        $this->assertSame(BuktiPotong::DITERBITKAN, $bukti($fakturD)->status);
        $this->assertSame('NOMOR-UJI-DUP', $bukti($fakturD)->nomor_bukti_djp);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sudah dipakai');
        $this->service()->terbitkan($bukti($fakturE), 'NOMOR-UJI-DUP', null, $this->finance());
    }

    public function test_an_issued_withholding_keeps_its_recipient_snapshot_when_the_supplier_master_changes(): void
    {
        $supplier = $this->supplier();
        $faktur = $this->faktur($supplier);
        $pembayaran = $this->bayar($faktur, self::MASA_1 . '-15', 100000);

        $this->service()->sinkron(self::MASA_1, $this->finance());
        $bukti = BuktiPotong::where('pembayaran_id', $pembayaran->id)->firstOrFail();
        $namaAsli = $bukti->penerima_nama;
        $npwpAsli = $bukti->penerima_npwp;

        $this->service()->terbitkan($bukti, 'NOMOR-SNAP', null, $this->finance());

        $supplier->update(['nama' => 'Nama Baru Setelah Terbit', 'npwp' => '0999999999999000']);
        $this->service()->sinkron(self::MASA_1, $this->finance());

        $bukti->refresh();
        $this->assertSame($namaAsli, $bukti->penerima_nama);
        $this->assertSame($npwpAsli, $bukti->penerima_npwp);
    }

    public function test_a_draft_follows_the_supplier_master_until_it_is_issued(): void
    {
        $supplier = $this->supplier(['nitku' => null]);
        $faktur = $this->faktur($supplier);
        $pembayaran = $this->bayar($faktur, self::MASA_1 . '-15', 100000);

        $this->service()->sinkron(self::MASA_1, $this->finance());
        $this->assertNull(BuktiPotong::where('pembayaran_id', $pembayaran->id)->value('penerima_nitku'));

        $supplier->update(['nitku' => '0123456789012002000000']);
        $hasil = $this->service()->sinkron(self::MASA_1, $this->finance());

        $this->assertSame(1, $hasil['diperbarui']);
        $this->assertSame('0123456789012002000000', BuktiPotong::where('pembayaran_id', $pembayaran->id)->value('penerima_nitku'));
    }

    public function test_a_correction_replaces_an_issued_withholding_with_a_new_draft_version(): void
    {
        $faktur = $this->faktur($this->supplier());
        $pembayaran = $this->bayar($faktur, self::MASA_1 . '-15', 100000);
        $this->service()->sinkron(self::MASA_1, $this->finance());
        $lama = BuktiPotong::where('pembayaran_id', $pembayaran->id)->firstOrFail();

        $this->service()->terbitkan($lama, 'NOMOR-KOREKSI', null, $this->finance());
        $baru = $this->service()->buatPembetulan($lama, $this->finance());

        $this->assertSame(BuktiPotong::DIGANTI, $lama->fresh()->status);
        $this->assertSame(BuktiPotong::DRAFT, $baru->status);
        $this->assertSame(2, $baru->versi);
        $this->assertSame($lama->id, $baru->menggantikan_id);

        $hasil = $this->service()->sinkron(self::MASA_1, $this->finance());
        $this->assertSame(0, $hasil['dibuat'], 'Sinkronisasi tidak boleh membuat bukti baru bila sudah ada pembetulan.');
    }

    public function test_the_ledger_tie_out_matches_and_still_matches_after_a_payment_is_reversed(): void
    {
        $faktur = $this->faktur($this->supplier());
        $a = $this->bayar($faktur, self::MASA_1 . '-10', 100000, true);
        $b = $this->bayar($faktur, self::MASA_1 . '-20', 100000, true);
        $this->service()->sinkron(self::MASA_1, $this->finance());

        $tie = $this->service()->tieOut(self::MASA_1)['PPH23'];
        $this->assertTrue($tie['bisa_dihitung']);
        $this->assertSame(200000.0, $tie['buku_besar']);
        $this->assertSame(0.0, $tie['selisih']);

        $this->batalkanPembayaran($b, true);
        $this->service()->sinkron(self::MASA_1, $this->finance());

        $tie = $this->service()->tieOut(self::MASA_1)['PPH23'];
        $this->assertSame(100000.0, $tie['bukti']);
        $this->assertSame(100000.0, $tie['buku_besar'], 'Jurnal pembalik harus mengurangi sisi buku besar pada masa jurnal aslinya.');
        $this->assertSame(0.0, $tie['selisih']);
    }

    public function test_the_tie_out_shows_a_difference_instead_of_hiding_it(): void
    {
        $faktur = $this->faktur($this->supplier());
        $a = $this->bayar($faktur, self::MASA_1 . '-10', 100000, true);
        $this->service()->sinkron(self::MASA_1, $this->finance());
        BuktiPotong::where('pembayaran_id', $a->id)->update(['jumlah_pph' => 90000]);

        $this->assertSame(-10000.0, $this->service()->tieOut(self::MASA_1)['PPH23']['selisih']);

        $this->actingAs(User::where('type', User::ROLE_ACCOUNTING)->firstOrFail())
            ->get(route('bukti-potong.index', ['masa' => self::MASA_1]))
            ->assertOk()
            ->assertSee('text-error', false);
    }

    public function test_preflight_reports_payments_without_a_withholding_and_recipients_without_a_valid_npwp(): void
    {
        $supplier = $this->supplier(['npwp' => null, 'nik' => '3201234567890001', 'tipe_identitas' => 'NIK', 'nitku' => null]);
        $faktur = $this->faktur($supplier);
        $this->bayar($faktur, self::MASA_1 . '-15', 100000);

        $sebelum = app(KepatuhanPajakService::class)->periksa(self::MASA_1)['ringkasan'];
        $this->assertSame(1, $sebelum[KepatuhanPajakService::PEMBAYARAN_PPH_TANPA_BUKTI_POTONG]);
        $this->assertSame(0, BuktiPotong::where('tanggal_pemotongan', '>=', '2032-01-01')->count());

        $this->service()->sinkron(self::MASA_1, $this->finance());
        $sesudah = app(KepatuhanPajakService::class)->periksa(self::MASA_1)['ringkasan'];

        $this->assertSame(0, $sesudah[KepatuhanPajakService::PEMBAYARAN_PPH_TANPA_BUKTI_POTONG]);
        $this->assertSame(1, $sesudah[KepatuhanPajakService::PENERIMA_TANPA_NPWP_VALID]);
        $this->assertSame(0, $sesudah[KepatuhanPajakService::BUKTI_POTONG_IDENTITAS_TIDAK_LENGKAP], 'NIK 16 digit adalah identitas yang cukup untuk penerbitan.');

        $tanpaKode = $this->faktur($this->supplier(), ['kode_objek_pajak' => null]);
        $this->bayar($tanpaKode, self::MASA_1 . '-16', 100000);
        $this->service()->sinkron(self::MASA_1, $this->finance());

        $this->assertSame(1, app(KepatuhanPajakService::class)->periksa(self::MASA_1)['ringkasan'][KepatuhanPajakService::BUKTI_POTONG_TANPA_KODE_OBJEK]);
    }

    public function test_the_preflight_never_writes(): void
    {
        $this->duaPembayaranDiDuaMasa();

        DB::enableQueryLog();
        app(KepatuhanPajakService::class)->periksa(self::MASA_1);
        $tulis = collect(DB::getQueryLog())->filter(fn ($q) => preg_match('/^\s*(insert|update|delete)/i', $q['query']));
        DB::disableQueryLog();

        $this->assertCount(0, $tulis);
    }

    public function test_the_xml_export_follows_the_official_example_structure_and_is_blocked_by_preflight(): void
    {
        $faktur = $this->faktur($this->supplier(), ['tanggal' => self::MASA_1 . '-02']);
        $this->bayar($faktur, self::MASA_1 . '-15', 100000);
        $ekspor = app(BuktiPotongExportService::class);

        try {
            $ekspor->xml(self::MASA_1, 'SEMUA');
            $this->fail('Ekspor harus diblokir selama pembayaran belum punya bukti potong.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(KepatuhanPajakService::PEMBAYARAN_PPH_TANPA_BUKTI_POTONG, $e->getMessage());
        }

        $this->service()->sinkron(self::MASA_1, $this->finance());

        $dokumen = new \DOMDocument();
        $this->assertTrue($dokumen->loadXML($ekspor->xml(self::MASA_1, 'DRAFT')));
        $xpath = new \DOMXPath($dokumen);

        $this->assertSame('BpuBulk', $dokumen->documentElement->nodeName);
        $this->assertSame('0123456789012000', $xpath->evaluate('string(/BpuBulk/TIN)'));
        $this->assertSame('3', $xpath->evaluate('string(/BpuBulk/ListOfBpu/Bpu/TaxPeriodMonth)'));
        $this->assertSame('2032', $xpath->evaluate('string(/BpuBulk/ListOfBpu/Bpu/TaxPeriodYear)'));
        $this->assertSame('0123456789012002', $xpath->evaluate('string(//CounterpartTin)'));
        $this->assertSame('0123456789012002000000', $xpath->evaluate('string(//IDPlaceOfBusinessActivityOfIncomeRecipient)'));
        $this->assertSame('N/A', $xpath->evaluate('string(//TaxCertificate)'));
        $this->assertSame('24-104-29', $xpath->evaluate('string(//TaxObjectCode)'));
        $this->assertSame('5000000', $xpath->evaluate('string(//TaxBase)'));
        $this->assertSame('2', $xpath->evaluate('string(//Rate)'));
        $this->assertSame('CommercialInvoice', $xpath->evaluate('string(//Document)'));
        $this->assertSame($faktur->no_invoice, $xpath->evaluate('string(//DocumentNumber)'));
        $this->assertSame(self::MASA_1 . '-02', $xpath->evaluate('string(//DocumentDate)'));
        $this->assertSame('0123456789012000000000', $xpath->evaluate('string(//IDPlaceOfBusinessActivity)'));
        $this->assertSame('N/A', $xpath->evaluate('string(//GovTreasurerOpt)'));
        $this->assertSame('true', $xpath->evaluate('string(//SP2DNumber/@*[local-name()="nil"])'));
        $this->assertSame(self::MASA_1 . '-15', $xpath->evaluate('string(//WithholdingDate)'));

        config(['app.npwp_perusahaan' => '']);
        $this->expectException(RuntimeException::class);
        $ekspor->xml(self::MASA_1, 'DRAFT');
    }

    public function test_the_internal_excel_rows_list_the_selected_statuses(): void
    {
        $faktur = $this->faktur($this->supplier());
        $this->bayar($faktur, self::MASA_1 . '-15', 100000);
        $this->service()->sinkron(self::MASA_1, $this->finance());

        $baris = app(BuktiPotongExportService::class)->barisExcel(self::MASA_1, 'DRAFT');
        $this->assertCount(1, $baris);
        $this->assertSame(100000.0, $baris->first()['jumlah_pph']);
        $this->assertCount(0, app(BuktiPotongExportService::class)->barisExcel(self::MASA_1, 'DITERBITKAN'));
    }

    public function test_finance_can_sync_open_and_issue_a_withholding_through_the_routes(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $finance = $this->finance();
        $faktur = $this->faktur($this->supplier());
        $pembayaran = $this->bayar($faktur, self::MASA_1 . '-15', 100000);

        $this->actingAs($finance)->post(route('bukti-potong.sinkron'), ['masa' => self::MASA_1])
            ->assertRedirect(route('bukti-potong.index', ['masa' => self::MASA_1]));

        $bukti = BuktiPotong::where('pembayaran_id', $pembayaran->id)->firstOrFail();
        $this->assertSame(BuktiPotong::DRAFT, $bukti->status);

        $this->actingAs($finance)->get(route('bukti-potong.index', ['masa' => self::MASA_1]))->assertOk()->assertSee($bukti->penerima_nama);
        $this->actingAs($finance)->get(route('bukti-potong.show', $bukti))->assertOk()->assertSee('Terbitkan');

        $this->actingAs($finance)->post(route('bukti-potong.terbitkan', $bukti), ['nomor_bukti_djp' => ''])->assertSessionHasErrors('nomor_bukti_djp');
        $this->actingAs($finance)->post(route('bukti-potong.terbitkan', $bukti), ['nomor_bukti_djp' => 'NOMOR-HTTP-1'])
            ->assertRedirect(route('bukti-potong.show', $bukti));

        $bukti->refresh();
        $this->assertSame(BuktiPotong::DITERBITKAN, $bukti->status);
        $this->assertSame($finance->id, $bukti->diterbitkan_oleh);

        $this->actingAs($finance)->post(route('bukti-potong.terbitkan', $bukti), ['nomor_bukti_djp' => 'NOMOR-HTTP-2'])
            ->assertSessionHasErrors('bukti_potong');

        $this->actingAs($finance)->post(route('bukti-potong.batalkan', $bukti), ['alasan' => 'x'])->assertSessionHasErrors('alasan');
        $this->actingAs($finance)->post(route('bukti-potong.pembetulan', $bukti))->assertRedirect();
        $this->assertSame(BuktiPotong::DIGANTI, $bukti->fresh()->status);
    }

    public function test_a_blocked_xml_export_redirects_back_with_the_reason_instead_of_failing(): void
    {
        $faktur = $this->faktur($this->supplier());
        $this->bayar($faktur, self::MASA_1 . '-15', 100000);

        $this->actingAs($this->finance())
            ->get(route('bukti-potong.xml', ['masa' => self::MASA_1, 'status' => 'SEMUA']))
            ->assertRedirect(route('bukti-potong.index', ['masa' => self::MASA_1]))
            ->assertSessionHasErrors('ekspor');
    }

    public static function kontrakRole(): array
    {
        return [
            'finance' => [User::ROLE_FINANCE, true],
            'accounting' => [User::ROLE_ACCOUNTING, true],
            'purchasing' => [User::ROLE_PURCHASING, false],
            'warehouse' => [User::ROLE_WAREHOUSE, false],
            'produksi' => [User::ROLE_PRODUCTION, false],
            'accounting_manager' => [User::ROLE_ACCOUNTING_MANAGER, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kontrakRole')]
    public function test_roles_see_the_withholding_pages_only_when_the_contract_says_so(int $tipe, bool $boleh): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $pengguna = User::factory()->create(['type' => $tipe, 'is_active' => true]);
        $faktur = $this->faktur($this->supplier());
        $pembayaran = $this->bayar($faktur, self::MASA_1 . '-15', 100000);
        $this->service()->sinkron(self::MASA_1, $this->finance());
        $bukti = BuktiPotong::where('pembayaran_id', $pembayaran->id)->firstOrFail();

        $rute = [
            ['get', route('bukti-potong.index')],
            ['get', route('bukti-potong.show', $bukti)],
            ['post', route('bukti-potong.sinkron'), ['masa' => self::MASA_1]],
            ['post', route('bukti-potong.terbitkan', $bukti), ['nomor_bukti_djp' => 'NOMOR-ROLE']],
            ['post', route('bukti-potong.batalkan', $bukti), ['alasan' => 'alasan uji']],
            ['post', route('bukti-potong.pembetulan', $bukti)],
            ['get', route('bukti-potong.excel', ['masa' => self::MASA_1, 'status' => 'SEMUA'])],
        ];

        foreach ($rute as $item) {
            $respons = $this->actingAs($pengguna)->{$item[0]}($item[1], $item[2] ?? []);

            if ($boleh) {
                $this->assertNotSame(403, $respons->getStatusCode(), $item[1]);
            } else {
                $this->assertSame(403, $respons->getStatusCode(), $item[1]);
            }
        }

        $this->assertSame($boleh, $this->actingAs($pengguna)->get(route('bukti-potong.index'))->getStatusCode() === 200);
        $this->assertSame($boleh, BuktiPotong::find($bukti->id)->status !== BuktiPotong::DRAFT);
    }

    public function test_guests_are_sent_to_login_on_every_withholding_route(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $faktur = $this->faktur($this->supplier());
        $pembayaran = $this->bayar($faktur, self::MASA_1 . '-15', 100000);
        $this->service()->sinkron(self::MASA_1, $this->finance());
        $bukti = BuktiPotong::where('pembayaran_id', $pembayaran->id)->firstOrFail();

        $this->get(route('bukti-potong.index'))->assertRedirect(route('login'));
        $this->get(route('bukti-potong.show', $bukti))->assertRedirect(route('login'));
        $this->post(route('bukti-potong.sinkron'))->assertRedirect(route('login'));
        $this->post(route('bukti-potong.terbitkan', $bukti), ['nomor_bukti_djp' => 'X'])->assertRedirect(route('login'));
        $this->post(route('bukti-potong.batalkan', $bukti), ['alasan' => 'abcde'])->assertRedirect(route('login'));
        $this->post(route('bukti-potong.pembetulan', $bukti))->assertRedirect(route('login'));
        $this->get(route('bukti-potong.xml'))->assertRedirect(route('login'));
        $this->get(route('bukti-potong.excel'))->assertRedirect(route('login'));
    }

    public function test_the_coretax_pdf_can_be_attached_and_only_the_uploader_can_remove_it(): void
    {
        Storage::fake('lampiran');
        $faktur = $this->faktur($this->supplier());
        $pembayaran = $this->bayar($faktur, self::MASA_1 . '-15', 100000);
        $this->service()->sinkron(self::MASA_1, $this->finance());
        $bukti = BuktiPotong::where('pembayaran_id', $pembayaran->id)->firstOrFail();
        $finance = $this->finance();
        $akuntansi = User::where('type', User::ROLE_ACCOUNTING)->firstOrFail();

        $this->actingAs($finance)->post(route('lampiran.store'), [
            'lampiran_type' => 'bukti-potong',
            'lampiran_id' => $bukti->id,
            'kategori' => 'BUKTI_POTONG',
            'berkas' => UploadedFile::fake()->create('bupot.pdf', 100, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $lampiran = LampiranDokumen::where('lampiran_type', BuktiPotong::class)->where('lampiran_id', $bukti->id)->firstOrFail();

        $this->actingAs($akuntansi)->delete(route('lampiran.destroy', $lampiran))->assertForbidden();
        $this->actingAs($finance)->delete(route('lampiran.destroy', $lampiran))->assertRedirect();
        $this->assertDatabaseMissing('wms_lampiran_dokumen', ['id' => $lampiran->id]);
    }

    public function test_the_seeded_dataset_exercises_every_branch_of_the_withholding_flow(): void
    {
        $this->assertGreaterThan(0, BuktiPotong::where('status', BuktiPotong::DRAFT)->count());
        $this->assertGreaterThan(0, BuktiPotong::where('status', BuktiPotong::DITERBITKAN)->count());
        $this->assertGreaterThan(0, BuktiPotong::where('status', BuktiPotong::DIBATALKAN)->count());
        $this->assertGreaterThan(0, BuktiPotong::where('jenis_pph', 'PPH4A2')->count());
        $this->assertGreaterThan(0, BuktiPotong::where('jenis_pph', 'PPH23')->count());
        $this->assertGreaterThan(0, PembayaranFaktur::where('status', PembayaranFaktur::VOID)->where('potongan_pph', '>', 0)->count());

        $duaMasa = PembayaranFaktur::where('status', PembayaranFaktur::POSTED)->where('potongan_pph', '>', 0)
            ->get()
            ->groupBy('invoice_lpb_id')
            ->filter(fn ($grup) => $grup->map(fn ($p) => $p->tanggal_pembayaran->format('Y-m'))->unique()->count() > 1);
        $this->assertGreaterThan(0, $duaMasa->count(), 'Harus ada faktur yang dipotong di dua masa berbeda.');
    }

    private function hitungQueryPembayaran(callable $aksi): int
    {
        $jumlah = 0;

        DB::listen(function ($query) use (&$jumlah) {
            if (preg_match('/^select \* from `wms_pembayaran_faktur` where `status` = \? and `potongan_pph` > \?/i', $query->sql)) {
                $jumlah++;
            }
        });

        $aksi();

        return $jumlah;
    }

    public function test_the_withholding_page_and_the_tax_report_read_the_withholding_payments_once(): void
    {
        $this->duaPembayaranDiDuaMasa();
        $this->service()->sinkron(self::MASA_1, $this->finance());
        $akuntan = User::where('type', User::ROLE_ACCOUNTING)->firstOrFail();

        $halamanBukti = $this->hitungQueryPembayaran(
            fn () => $this->actingAs($akuntan)->get(route('bukti-potong.index', ['masa' => self::MASA_1]))->assertOk()
        );
        $this->assertSame(1, $halamanBukti);

        $laporan = $this->hitungQueryPembayaran(
            fn () => $this->actingAs($akuntan)->get(route('laporan-pajak-djp.index', ['masa' => self::MASA_1]))->assertOk()
        );
        $this->assertSame(1, $laporan);
    }

    public function test_sharing_the_payments_does_not_change_the_preflight_result(): void
    {
        $this->duaPembayaranDiDuaMasa();
        $this->service()->sinkron(self::MASA_1, $this->finance());
        $tanpaKode = $this->faktur($this->supplier(), ['kode_objek_pajak' => null]);
        $this->bayar($tanpaKode, self::MASA_1 . '-16', 100000);
        $kepatuhan = app(KepatuhanPajakService::class);

        $mandiri = $kepatuhan->periksa(self::MASA_1);
        $berbagi = $kepatuhan->periksa(self::MASA_1, $this->service()->pembayaranBerpph(self::MASA_1));

        $this->assertSame($mandiri['temuan']->all(), $berbagi['temuan']->all());
        $this->assertSame($mandiri['ringkasan'], $berbagi['ringkasan']);
        $this->assertSame(
            $kepatuhan->temuanBuktiPotong(self::MASA_1)->all(),
            $kepatuhan->temuanBuktiPotongDari($mandiri)->all()
        );
        $this->assertGreaterThan(0, $kepatuhan->temuanBuktiPotongDari($mandiri)->count());
    }

    public function test_a_nik_only_recipient_without_a_nitku_blocks_the_xml_export_in_the_preflight(): void
    {
        $supplier = $this->supplier(['npwp' => null, 'nik' => '3201234567890001', 'tipe_identitas' => 'NIK', 'nitku' => null]);
        $this->bayar($this->faktur($supplier), self::MASA_1 . '-15', 100000);
        $this->service()->sinkron(self::MASA_1, $this->finance());

        $ringkasan = app(KepatuhanPajakService::class)->periksa(self::MASA_1)['ringkasan'];
        $this->assertSame(1, $ringkasan[KepatuhanPajakService::BUKTI_POTONG_TANPA_NITKU]);
        $this->assertSame(0, $ringkasan[KepatuhanPajakService::BUKTI_POTONG_IDENTITAS_TIDAK_LENGKAP]);

        $kategori = app(BuktiPotongExportService::class)->penghalang(self::MASA_1)->pluck('kategori');
        $this->assertTrue($kategori->contains(KepatuhanPajakService::BUKTI_POTONG_TANPA_NITKU));

        $this->actingAs($this->finance())
            ->get(route('bukti-potong.index', ['masa' => self::MASA_1]))
            ->assertOk()
            ->assertSee('Ekspor XML diblokir')
            ->assertSee(KepatuhanPajakService::BUKTI_POTONG_TANPA_NITKU);
    }

    public function test_an_inactive_or_mismatched_object_code_blocks_the_xml_export_in_the_preflight(): void
    {
        $tidakCocok = $this->faktur($this->supplier(), ['kode_objek_pajak' => '28-403-02']);
        $this->bayar($tidakCocok, self::MASA_1 . '-12', 100000);
        $this->service()->sinkron(self::MASA_1, $this->finance());

        $ringkasan = app(KepatuhanPajakService::class)->periksa(self::MASA_1)['ringkasan'];
        $this->assertSame(1, $ringkasan[KepatuhanPajakService::BUKTI_POTONG_KODE_OBJEK_TIDAK_VALID]);
        $this->assertSame(0, $ringkasan[KepatuhanPajakService::BUKTI_POTONG_TANPA_KODE_OBJEK]);

        $nonaktif = $this->faktur($this->supplier(), ['kode_objek_pajak' => '24-104-29']);
        $this->bayar($nonaktif, self::MASA_1 . '-13', 100000);
        $this->service()->sinkron(self::MASA_1, $this->finance());
        KodeObjekPajak::where('kode', '24-104-29')->update(['is_active' => false]);

        $hasil = app(KepatuhanPajakService::class)->periksa(self::MASA_1);
        $this->assertSame(2, $hasil['ringkasan'][KepatuhanPajakService::BUKTI_POTONG_KODE_OBJEK_TIDAK_VALID]);

        $kategori = app(BuktiPotongExportService::class)->penghalang(self::MASA_1)->pluck('kategori');
        $this->assertTrue($kategori->contains(KepatuhanPajakService::BUKTI_POTONG_KODE_OBJEK_TIDAK_VALID));
    }

    public function test_a_duplicate_withholding_number_raced_past_the_check_becomes_a_validation_error_not_a_500(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $finance = $this->finance();

        foreach ([self::MASA_1 . '-10', self::MASA_1 . '-11'] as $tanggal) {
            $this->bayar($this->faktur($this->supplier()), $tanggal, 100000);
        }

        $this->service()->sinkron(self::MASA_1, $finance);
        $bukti = BuktiPotong::where('status', BuktiPotong::DRAFT)->orderByDesc('id')->take(2)->get();
        $this->assertCount(2, $bukti);
        [$pertama, $kedua] = [$bukti[0], $bukti[1]];

        $this->actingAs($finance)->post(route('bukti-potong.terbitkan', $pertama), ['nomor_bukti_djp' => 'NOMOR-BALAP'])
            ->assertRedirect(route('bukti-potong.show', $pertama));

        $aktif = true;
        BuktiPotong::updating(function (BuktiPotong $model) use (&$aktif, $pertama) {
            if ($aktif && $model->nomor_bukti_djp === 'NOMOR-BALAP-2') {
                DB::table('wms_bukti_potong')->where('id', $pertama->id)->update(['nomor_bukti_djp' => 'NOMOR-BALAP-2']);
            }
        });

        $this->actingAs($finance)->post(route('bukti-potong.terbitkan', $kedua), ['nomor_bukti_djp' => 'NOMOR-BALAP-2'])
            ->assertSessionHasErrors('bukti_potong');
        $aktif = false;

        $this->assertStringContainsString('sudah dipakai', session('errors')->first('bukti_potong'));
        $this->assertSame(BuktiPotong::DRAFT, $kedua->fresh()->status);
    }
}
