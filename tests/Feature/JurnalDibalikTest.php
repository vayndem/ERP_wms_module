<?php

namespace Tests\Feature;

use App\Models\BaganAkun;
use App\Models\Jurnal;
use App\Models\PenerimaanBarang;
use App\Models\User;
use App\Services\FinancialStatementService;
use App\Services\InventoryReversalService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class JurnalDibalikTest extends TestCase
{
    use DatabaseTransactions;

    private function saldoPerAkun(): array
    {
        $asOf = now()->addYear();
        $trial = app(FinancialStatementService::class)->trialBalance($asOf);
        $saldo = [];
        foreach ($trial['rows'] as $row) {
            $saldo[$row['account']->kode_akun] = round($row['debit'] - $row['kredit'], 2);
        }

        return $saldo;
    }

    private function labaRugi(): array
    {
        $laporan = app(FinancialStatementService::class)->incomeStatement(now()->subYears(5), now()->addYear());
        $hasil = [];
        foreach (['pendapatan', 'beban'] as $bagian) {
            foreach ($laporan[$bagian] as $baris) {
                $hasil[$bagian][$baris['account']->kode_akun] = round($baris['amount'], 2);
            }
        }

        return $hasil;
    }

    private function neraca(): array
    {
        $laporan = app(FinancialStatementService::class)->balanceSheet(now()->addYear());

        return [
            round($laporan['total_aset'], 2),
            round($laporan['total_liabilitas'], 2),
            round($laporan['total_ekuitas'], 2),
        ];
    }

    public function test_reversing_an_lpb_returns_every_report_to_its_prior_value(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        Auth::login($user);
        $lpb = PenerimaanBarang::where('document_type', 'GOODS')->whereDoesntHave('invoiceReceipts')->firstOrFail();
        $journal = Jurnal::with('details')->where('sumber_transaksi', 'LPB')->where('reff_id', $lpb->id)->first();
        $this->assertNotNull($journal);
        $this->assertSame('POSTED', $journal->status);

        $saldoSebelum = $this->saldoPerAkun();
        $labaRugiSebelum = $this->labaRugi();
        $kodeAkun = BaganAkun::pluck('kode_akun', 'id');
        $efekLpb = [];
        foreach ($journal->details as $baris) {
            $kode = $kodeAkun[$baris->coa_id];
            $efekLpb[$kode] = round(($efekLpb[$kode] ?? 0) + (float) $baris->debit - (float) $baris->kredit, 2);
        }
        $this->assertNotEmpty($efekLpb);
        $netoSebelum = $this->neraca();

        app(InventoryReversalService::class)->reverseLpb($lpb, 'Uji jurnal dibalik');

        $this->assertSame('REVERSED', $journal->fresh()->status);
        $diharapkan = $saldoSebelum;
        foreach ($efekLpb as $kode => $efek) {
            $diharapkan[$kode] = round($diharapkan[$kode] - $efek, 2);
        }
        $this->assertSame($diharapkan, $this->saldoPerAkun());
        $this->assertSame($labaRugiSebelum, $this->labaRugi());
        $this->assertNotSame($netoSebelum, [0.0, 0.0, 0.0]);
    }

    public function test_reversing_a_manual_journal_returns_every_report_to_its_prior_value(): void
    {
        $user = User::factory()->create(['type' => User::ROLE_ACCOUNTING]);
        $this->actingAs($user);
        $debit = BaganAkun::where('kategori_akun', 'ASET')->where('is_postable', true)->where('is_active', true)->orderBy('kode_akun')->firstOrFail();
        $kredit = BaganAkun::where('kategori_akun', 'PENDAPATAN')->where('is_postable', true)->where('is_active', true)->orderBy('kode_akun')->firstOrFail();

        $saldoSebelum = $this->saldoPerAkun();
        $neracaSebelum = $this->neraca();
        $labaRugiSebelum = $this->labaRugi();

        $jurnal = Jurnal::create([
            'no_jurnal' => 'JR-UJI-DIBALIK',
            'tanggal' => now()->toDateString(),
            'keterangan' => 'Jurnal manual uji',
            'sumber_transaksi' => 'MANUAL',
            'status' => 'POSTED',
            'created_by' => $user->id,
            'posted_by' => $user->id,
            'posted_at' => now(),
            'total_debit' => 750000,
            'total_kredit' => 750000,
        ]);
        $jurnal->details()->createMany([
            ['coa_id' => $debit->id, 'debit' => 750000, 'kredit' => 0, 'keterangan' => 'uji'],
            ['coa_id' => $kredit->id, 'debit' => 0, 'kredit' => 750000, 'keterangan' => 'uji'],
        ]);

        $this->assertNotSame($saldoSebelum, $this->saldoPerAkun());

        $this->post(route('jurnal.reverse', $jurnal->id))->assertOk();

        $this->assertSame('REVERSED', $jurnal->fresh()->status);
        $this->assertSame($saldoSebelum, $this->saldoPerAkun());
        $this->assertSame($neracaSebelum, $this->neraca());
        $this->assertSame($labaRugiSebelum, $this->labaRugi());
    }

    public function test_scope_berlaku_excludes_drafts_and_pending_journals(): void
    {
        $statuses = Jurnal::berlaku()->distinct()->pluck('status')->all();
        sort($statuses);

        $this->assertSame(['POSTED', 'REVERSED'], $statuses);
        $this->assertSame(0, Jurnal::berlaku()->whereIn('status', ['DRAFT', 'PENDING_APPROVAL'])->count());
    }
}
