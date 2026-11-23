<?php

namespace Tests\Feature;

use App\Models\FakturPenjualan;
use App\Models\JurnalDetail;
use App\Models\PenerimaanPembayaran;
use App\Models\ReturPenjualan;
use App\Services\FinancialStatementService;
use App\Services\LaporanPajakDjpService;
use App\Services\PiutangAgingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QueryTanggalSargableTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_read_indexes_exist(): void
    {
        foreach ([
            ['wms_pembayaran_faktur', 'pembayaran_faktur_status_tgl_idx'],
            ['wms_jurnal', 'jurnal_tanggal_idx'],
            ['wms_faktur_pembelian', 'faktur_pembelian_supplier_nsfp_idx'],
            ['wms_bukti_potong', 'bp_status_tgl_potong_idx'],
        ] as [$tabel, $nama]) {
            $this->assertTrue(Schema::hasIndex($tabel, $nama), "Indeks {$nama} pada {$tabel} belum ada.");
        }
    }

    public function test_output_tax_invoices_by_range_equal_the_year_month_filter_for_every_month_and_for_an_invalid_one(): void
    {
        $bulan = FakturPenjualan::whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
            ->get()
            ->map(fn (FakturPenjualan $faktur) => $faktur->tanggal->format('Y-m'))
            ->unique()
            ->values();

        $this->assertGreaterThan(0, $bulan->count(), 'Butuh faktur penjualan berstatus terbit pada data demo.');

        $service = app(LaporanPajakDjpService::class);

        foreach ($bulan->push('1999-01') as $masa) {
            [$tahun, $nomorBulan] = array_map('intval', explode('-', $masa));

            $lama = FakturPenjualan::whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
                ->whereYear('tanggal', $tahun)
                ->whereMonth('tanggal', $nomorBulan)
                ->pluck('nomor')
                ->sort()
                ->values()
                ->all();

            $baru = $service->fakturKeluaran($masa)->pluck('nomor_faktur')->sort()->values()->all();

            $this->assertSame($lama, $baru, "Masa {$masa} berubah hasilnya.");
        }

        $this->assertSame([], $service->fakturKeluaran('2026-13')->all());
        $this->assertSame([], $service->fakturKeluaran('2026-00')->all());
    }

    public function test_the_trial_balance_by_date_comparison_equals_the_date_function_filter(): void
    {
        $service = app(FinancialStatementService::class);

        foreach ([today(), today()->subMonths(2), today()->subYear(), Carbon::parse('2000-01-01')] as $tanggal) {
            $lama = JurnalDetail::query()
                ->whereHas('jurnal', fn ($q) => $q->berlaku()->whereRaw('DATE(tanggal) <= ?', [$tanggal->toDateString()]))
                ->selectRaw('coa_id, SUM(debit) as d, SUM(kredit) as k')
                ->groupBy('coa_id')
                ->get()
                ->mapWithKeys(fn ($baris) => [$baris->coa_id => [(float) $baris->d, (float) $baris->k]])
                ->all();

            $baru = $service->trialBalance($tanggal->copy()->setTime(13, 45))['rows']
                ->filter(fn ($baris) => $baris['debit'] != 0 || $baris['kredit'] != 0)
                ->mapWithKeys(fn ($baris) => [$baris['account']->id => [$baris['debit'], $baris['kredit']]])
                ->all();

            ksort($lama);
            ksort($baru);

            $this->assertEquals($lama, $baru, 'Neraca saldo ' . $tanggal->toDateString() . ' berubah.');
        }
    }

    public function test_receivables_by_date_comparison_equal_the_date_function_filter(): void
    {
        $service = app(PiutangAgingService::class);

        foreach ([today(), today()->subDays(20), today()->subDays(90), today()->addDays(5)] as $tanggal) {
            $batas = $tanggal->toDateString();
            $total = 0.0;

            $faktur = FakturPenjualan::whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
                ->whereRaw('DATE(tanggal) <= ?', [$batas])
                ->get();

            foreach ($faktur as $item) {
                $dibayar = (float) PenerimaanPembayaran::where('faktur_penjualan_id', $item->id)
                    ->where('status', PenerimaanPembayaran::POSTED)
                    ->whereRaw('DATE(tanggal) <= ?', [$batas])
                    ->sum('jumlah');

                $retur = (float) ReturPenjualan::where('faktur_penjualan_id', $item->id)
                    ->where('status', ReturPenjualan::POSTED)
                    ->whereRaw('DATE(tanggal) > ?', [$batas])
                    ->sum(DB::raw('total_dpp + total_ppn'));

                $sisa = round((float) $item->grand_total + $retur - $dibayar, 2);

                if ($sisa > 0.005) {
                    $total += $sisa;
                }
            }

            $this->assertEqualsWithDelta(
                round($total, 2),
                $service->laporan($tanggal->copy()->setTime(16, 30))['total_piutang'],
                0.005,
                "Piutang per {$batas} berubah."
            );
        }
    }
}
