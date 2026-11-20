<?php

namespace App\Services;

use App\Models\BaganAkun;
use App\Models\JurnalDetail;
use App\Models\KategoriAset;
use Illuminate\Support\Carbon;

class FinancialStatementService
{
    public function trialBalance(Carbon $asOf): array
    {
        $accounts = BaganAkun::orderBy('kode_akun')->get();

        $sums = JurnalDetail::query()
            ->whereHas('jurnal', fn ($q) => $q->berlaku()->whereDate('tanggal', '<=', $asOf))
            ->selectRaw('coa_id, SUM(debit) as total_debit, SUM(kredit) as total_kredit')
            ->groupBy('coa_id')
            ->get()
            ->keyBy('coa_id');

        $rows = $accounts->map(function (BaganAkun $account) use ($sums) {
            $sum = $sums->get($account->id);

            return [
                'account' => $account,
                'debit' => (float) ($sum->total_debit ?? 0),
                'kredit' => (float) ($sum->total_kredit ?? 0),
            ];
        })->values();

        return [
            'as_of' => $asOf,
            'rows' => $rows,
            'total_debit' => $rows->sum('debit'),
            'total_kredit' => $rows->sum('kredit'),
        ];
    }

    public function generalLedger(BaganAkun $account, Carbon $from, Carbon $to): array
    {
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $sign = $account->posisi_normal === 'DEBIT' ? 1 : -1;

        $openingSum = JurnalDetail::where('coa_id', $account->id)
            ->whereHas('jurnal', fn ($q) => $q->berlaku()->whereDate('tanggal', '<', $from))
            ->selectRaw('SUM(debit) as debit, SUM(kredit) as kredit')
            ->first();

        $opening = $sign * ((float) ($openingSum->debit ?? 0) - (float) ($openingSum->kredit ?? 0));

        $details = JurnalDetail::where('coa_id', $account->id)
            ->whereHas('jurnal', fn ($q) => $q->berlaku()->whereBetween('tanggal', [$from, $to]))
            ->with('jurnal')
            ->get()
            ->sortBy(fn (JurnalDetail $detail) => $detail->jurnal->tanggal->format('Y-m-d') . '-' . str_pad((string) $detail->id, 10, '0', STR_PAD_LEFT))
            ->values();

        $running = $opening;
        $rows = $details->map(function (JurnalDetail $detail) use (&$running, $sign) {
            $running += $sign * ((float) $detail->debit - (float) $detail->kredit);

            return [
                'tanggal' => $detail->jurnal->tanggal,
                'no_jurnal' => $detail->jurnal->no_jurnal,
                'keterangan' => $detail->keterangan ?: $detail->jurnal->keterangan,
                'debit' => (float) $detail->debit,
                'kredit' => (float) $detail->kredit,
                'balance' => $running,
            ];
        });

        return [
            'account' => $account,
            'from' => $from,
            'to' => $to,
            'opening_balance' => $opening,
            'rows' => $rows,
            'closing_balance' => $running,
        ];
    }

    public function incomeStatement(Carbon $from, Carbon $to): array
    {
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $sums = JurnalDetail::query()
            ->whereHas('coa', fn ($q) => $q->whereIn('kategori_akun', ['PENDAPATAN', 'BEBAN']))
            ->whereHas('jurnal', fn ($q) => $q->berlaku()->whereBetween('tanggal', [$from, $to]))
            ->selectRaw('coa_id, SUM(debit) as total_debit, SUM(kredit) as total_kredit')
            ->groupBy('coa_id')
            ->get()
            ->keyBy('coa_id');

        $accounts = BaganAkun::whereIn('kategori_akun', ['PENDAPATAN', 'BEBAN'])->orderBy('kode_akun')->get();

        $section = function (string $kategori, bool $normalCredit) use ($accounts, $sums) {
            return $accounts->where('kategori_akun', $kategori)->map(function (BaganAkun $account) use ($sums, $normalCredit) {
                $sum = $sums->get($account->id);
                $debit = (float) ($sum->total_debit ?? 0);
                $kredit = (float) ($sum->total_kredit ?? 0);

                return [
                    'account' => $account,
                    'amount' => $normalCredit ? ($kredit - $debit) : ($debit - $kredit),
                ];
            })->filter(fn ($row) => $row['amount'] != 0)->values();
        };

        $pendapatan = $section('PENDAPATAN', true);
        $beban = $section('BEBAN', false);

        $totalPendapatan = $pendapatan->sum('amount');
        $totalBeban = $beban->sum('amount');

        return [
            'from' => $from,
            'to' => $to,
            'pendapatan' => $pendapatan,
            'beban' => $beban,
            'total_pendapatan' => $totalPendapatan,
            'total_beban' => $totalBeban,
            'laba_bersih' => $totalPendapatan - $totalBeban,
        ];
    }

    public function cashFlow(Carbon $from, Carbon $to): array
    {
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $kasIds = BaganAkun::where('is_cash_bank', true)->pluck('id');
        $asetTetapIds = KategoriAset::whereNotNull('akun_aset_id')->distinct()->pluck('akun_aset_id');

        $saldoAwal = $this->saldoKas($kasIds, null, $from->copy()->subDay());

        $jurnalIds = JurnalDetail::whereIn('coa_id', $kasIds)
            ->whereHas('jurnal', fn ($q) => $q->berlaku()->whereBetween('tanggal', [$from, $to]))
            ->distinct()->pluck('jurnal_id');

        $details = JurnalDetail::whereIn('jurnal_id', $jurnalIds)->with(['jurnal', 'coa'])->get();

        $buckets = ['OPERASI' => [], 'INVESTASI' => [], 'PENDANAAN' => []];

        foreach ($details->groupBy('jurnal_id') as $lines) {
            $kasLines = $lines->filter(fn ($line) => $kasIds->contains($line->coa_id));
            $lawanLines = $lines->reject(fn ($line) => $kasIds->contains($line->coa_id));
            $arusKas = (float) $kasLines->sum('debit') - (float) $kasLines->sum('kredit');
            if (abs($arusKas) < 0.005) {
                continue;
            }

            $dominan = $lawanLines->sortByDesc(fn ($line) => abs((float) $line->debit - (float) $line->kredit))->first();
            $akunLawan = $dominan?->coa;
            $kelompok = match (true) {
                $akunLawan === null => 'OPERASI',
                $asetTetapIds->contains($akunLawan->id) => 'INVESTASI',
                $akunLawan->kategori_akun === 'EKUITAS' => 'PENDANAAN',
                default => 'OPERASI',
            };

            $kunci = $akunLawan?->id ?? 0;
            $buckets[$kelompok][$kunci] ??= ['account' => $akunLawan, 'amount' => 0.0];
            $buckets[$kelompok][$kunci]['amount'] += $arusKas;
        }

        $sections = collect($buckets)->map(function (array $rows, string $kelompok) {
            $baris = collect($rows)->values()
                ->filter(fn ($row) => abs($row['amount']) >= 0.005)
                ->sortByDesc(fn ($row) => abs($row['amount']))->values();

            return [
                'kelompok' => $kelompok,
                'rows' => $baris,
                'subtotal' => round($baris->sum('amount'), 2),
            ];
        })->values();

        $arusBersih = round($sections->sum('subtotal'), 2);
        $saldoAkhirBuku = $this->saldoKas($kasIds, null, $to);

        return [
            'from' => $from,
            'to' => $to,
            'sections' => $sections,
            'saldo_awal' => $saldoAwal,
            'arus_bersih' => $arusBersih,
            'saldo_akhir' => round($saldoAwal + $arusBersih, 2),
            'saldo_akhir_buku' => $saldoAkhirBuku,
            'selisih' => round($saldoAwal + $arusBersih - $saldoAkhirBuku, 2),
        ];
    }

    private function saldoKas($kasIds, ?Carbon $from, Carbon $to): float
    {
        $sum = JurnalDetail::whereIn('coa_id', $kasIds)
            ->whereHas('jurnal', function ($q) use ($from, $to) {
                $q->berlaku()->whereDate('tanggal', '<=', $to);
                if ($from) {
                    $q->whereDate('tanggal', '>=', $from);
                }
            })
            ->selectRaw('SUM(debit) as debit, SUM(kredit) as kredit')->first();

        return round((float) ($sum->debit ?? 0) - (float) ($sum->kredit ?? 0), 2);
    }

    public function balanceSheet(Carbon $asOf): array
    {
        $trial = $this->trialBalance($asOf);
        $byCategory = $trial['rows']->groupBy(fn ($row) => $row['account']->kategori_akun);

        $section = function (string $kategori, bool $normalDebit) use ($byCategory) {
            return ($byCategory->get($kategori) ?? collect())->map(function ($row) use ($normalDebit) {
                return [
                    'account' => $row['account'],
                    'amount' => $normalDebit ? ($row['debit'] - $row['kredit']) : ($row['kredit'] - $row['debit']),
                ];
            })->filter(fn ($row) => $row['amount'] != 0)->values();
        };

        $aset = $section('ASET', true);
        $liabilitas = $section('LIABILITAS', false);
        $ekuitas = $section('EKUITAS', false);

        $totalAset = $aset->sum('amount');
        $totalLiabilitas = $liabilitas->sum('amount');
        $labaDitahanBerjalan = $this->cumulativeNetIncome($asOf);
        $totalEkuitas = $ekuitas->sum('amount') + $labaDitahanBerjalan;

        return [
            'as_of' => $asOf,
            'aset' => $aset,
            'liabilitas' => $liabilitas,
            'ekuitas' => $ekuitas,
            'laba_ditahan_berjalan' => $labaDitahanBerjalan,
            'total_aset' => $totalAset,
            'total_liabilitas' => $totalLiabilitas,
            'total_ekuitas' => $totalEkuitas,
        ];
    }

    public function changesInEquity(Carbon $from, Carbon $to): array
    {
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $sebelum = $from->copy()->subDay();
        $accounts = BaganAkun::where('kategori_akun', 'EKUITAS')->orderBy('kode_akun')->get();
        $accountIds = $accounts->pluck('id');

        $saldoAwal = $this->mutasiEkuitas($accountIds, null, $sebelum);
        $mutasi = $this->mutasiEkuitas($accountIds, $from, $to);

        $komponen = $accounts->map(function (BaganAkun $account) use ($saldoAwal, $mutasi) {
            $awal = $saldoAwal->get($account->id);
            $gerak = $mutasi->get($account->id);

            $awalBersih = round((float) ($awal->kredit ?? 0) - (float) ($awal->debit ?? 0), 2);
            $setoran = round((float) ($gerak->kredit ?? 0), 2);
            $penarikan = round((float) ($gerak->debit ?? 0), 2);

            return [
                'account' => $account,
                'nama' => $account->kode_akun . ' — ' . $account->nama_akun,
                'saldo_awal' => $awalBersih,
                'setoran' => $setoran,
                'penarikan' => $penarikan,
                'laba_bersih' => 0.0,
                'saldo_akhir' => round($awalBersih + $setoran - $penarikan, 2),
            ];
        })->filter(fn ($row) => $row['saldo_awal'] != 0 || $row['setoran'] != 0 || $row['penarikan'] != 0)->values();

        $labaDitahanAwal = round($this->cumulativeNetIncome($sebelum), 2);
        $labaBersih = round((float) $this->incomeStatement($from, $to)['laba_bersih'], 2);

        $komponen = $komponen->push([
            'account' => null,
            'nama' => 'Laba (Rugi) Ditahan',
            'saldo_awal' => $labaDitahanAwal,
            'setoran' => 0.0,
            'penarikan' => 0.0,
            'laba_bersih' => $labaBersih,
            'saldo_akhir' => round($labaDitahanAwal + $labaBersih, 2),
        ]);

        $total = fn (string $kolom) => round($komponen->sum($kolom), 2);
        $saldoAkhir = $total('saldo_akhir');
        $saldoAkhirBuku = round((float) $this->balanceSheet($to)['total_ekuitas'], 2);

        return [
            'from' => $from,
            'to' => $to,
            'komponen' => $komponen,
            'total_saldo_awal' => $total('saldo_awal'),
            'total_setoran' => $total('setoran'),
            'total_penarikan' => $total('penarikan'),
            'total_laba_bersih' => $total('laba_bersih'),
            'total_saldo_akhir' => $saldoAkhir,
            'saldo_akhir_buku' => $saldoAkhirBuku,
            'selisih' => round($saldoAkhir - $saldoAkhirBuku, 2),
        ];
    }

    private function mutasiEkuitas($accountIds, ?Carbon $from, Carbon $to)
    {
        return JurnalDetail::whereIn('coa_id', $accountIds)
            ->whereHas('jurnal', function ($q) use ($from, $to) {
                $q->berlaku()->whereDate('tanggal', '<=', $to);
                if ($from) {
                    $q->whereDate('tanggal', '>=', $from);
                }
            })
            ->selectRaw('coa_id, SUM(debit) as debit, SUM(kredit) as kredit')
            ->groupBy('coa_id')
            ->get()
            ->keyBy('coa_id');
    }

    private function cumulativeNetIncome(Carbon $asOf): float
    {
        $accountIds = BaganAkun::whereIn('kategori_akun', ['PENDAPATAN', 'BEBAN'])->pluck('id');

        $net = JurnalDetail::query()
            ->whereIn('coa_id', $accountIds)
            ->whereHas('jurnal', fn ($q) => $q->berlaku()->whereDate('tanggal', '<=', $asOf))
            ->selectRaw('SUM(kredit) - SUM(debit) as net')
            ->value('net');

        return (float) ($net ?? 0);
    }
}
