<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\Aset;
use App\Models\JurnalDetail;
use App\Models\KompensasiKerugianFiskal;
use App\Models\PerhitunganPajakPenghasilan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PajakPenghasilanService
{
    public const TARIF = 0.22;
    public const BATAS_FASILITAS = 4_800_000_000;
    public const BATAS_PEREDARAN = 50_000_000_000;
    public const MASA_KOMPENSASI_TAHUN = 5;

    public function __construct(
        private RekonsiliasiFiskalService $fiskal,
        private WmsAccountingService $accounting,
        private AccountingPeriodService $periods,
    ) {}

    public function hitung(int $tahun, float $peredaranBruto): array
    {
        $from = Carbon::create($tahun, 1, 1)->startOfDay();
        $to = Carbon::create($tahun, 12, 31)->startOfDay();
        $rekon = $this->fiskal->reconcile($from, $to);

        $labaFiskal = (float) $rekon['laba_fiskal'];
        $rincianKompensasi = $this->pakaiKerugian($tahun, max($labaFiskal, 0));
        $kompensasi = round(array_sum(array_column($rincianKompensasi, 'jumlah')), 2);
        $setelahKompensasi = round($labaFiskal - $kompensasi, 2);
        $pkp = $setelahKompensasi > 0 ? floor($setelahKompensasi / 1000) * 1000 : 0.0;
        [$pkpFasilitas, $pphTerutang] = $this->pphTerutang($pkp, $peredaranBruto);

        $bedaKumulatif = $this->bedaWaktuKumulatif();
        $seharusnya = round($bedaKumulatif * self::TARIF, 2);
        $tercatat = $this->pajakTangguhanTercatat();

        return [
            'tahun_pajak' => $tahun,
            'period_start' => $from,
            'period_end' => $to,
            'peredaran_bruto' => round($peredaranBruto, 2),
            'rekonsiliasi' => $rekon,
            'laba_komersial' => (float) $rekon['laba_komersial'],
            'koreksi_positif' => (float) $rekon['total_koreksi_positif'],
            'koreksi_negatif' => (float) $rekon['total_koreksi_negatif'],
            'koreksi_beda_waktu' => (float) $rekon['total_koreksi_beda_waktu'],
            'laba_fiskal' => $labaFiskal,
            'kompensasi_kerugian' => $kompensasi,
            'rincian_kompensasi' => $rincianKompensasi,
            'sisa_kerugian' => $this->sisaKerugian($tahun),
            'penghasilan_kena_pajak' => $pkp,
            'pkp_fasilitas' => $pkpFasilitas,
            'pkp_normal' => round($pkp - $pkpFasilitas, 2),
            'pph_terutang' => $pphTerutang,
            'beda_waktu_kumulatif' => $bedaKumulatif,
            'pajak_tangguhan_seharusnya' => $seharusnya,
            'pajak_tangguhan_tercatat' => $tercatat,
            'gerakan_pajak_tangguhan' => round($seharusnya - $tercatat, 2),
        ];
    }

    public function posting(int $tahun, float $peredaranBruto, $tanggalPosting): PerhitunganPajakPenghasilan
    {
        $this->periods->assertOpen($tanggalPosting, 'Jurnal PPh Badan');

        return DB::transaction(function () use ($tahun, $peredaranBruto, $tanggalPosting) {
            if (PerhitunganPajakPenghasilan::where('tahun_pajak', $tahun)->exists()) {
                throw new RuntimeException("Perhitungan PPh tahun {$tahun} sudah pernah diposting. Batalkan dulu kalau ingin menghitung ulang.");
            }

            $data = $this->hitung($tahun, $peredaranBruto);
            $lines = $this->barisJurnal($data);

            $perhitungan = PerhitunganPajakPenghasilan::create([
                'tahun_pajak' => $tahun,
                'period_start' => $data['period_start'],
                'period_end' => $data['period_end'],
                'peredaran_bruto' => $data['peredaran_bruto'],
                'laba_komersial' => $data['laba_komersial'],
                'koreksi_positif' => $data['koreksi_positif'],
                'koreksi_negatif' => $data['koreksi_negatif'],
                'koreksi_beda_waktu' => $data['koreksi_beda_waktu'],
                'laba_fiskal' => $data['laba_fiskal'],
                'kompensasi_kerugian' => $data['kompensasi_kerugian'],
                'penghasilan_kena_pajak' => $data['penghasilan_kena_pajak'],
                'pkp_fasilitas' => $data['pkp_fasilitas'],
                'pph_terutang' => $data['pph_terutang'],
                'beda_waktu_kumulatif' => $data['beda_waktu_kumulatif'],
                'pajak_tangguhan_seharusnya' => $data['pajak_tangguhan_seharusnya'],
                'pajak_tangguhan_tercatat' => $data['pajak_tangguhan_tercatat'],
                'gerakan_pajak_tangguhan' => $data['gerakan_pajak_tangguhan'],
                'posted_by' => Auth::id(),
            ]);

            foreach ($data['rincian_kompensasi'] as $baris) {
                $perhitungan->kompensasi()->create($baris);
            }

            if ($lines !== []) {
                $jurnal = $this->accounting->postPajakPenghasilan(
                    $perhitungan->id,
                    $tanggalPosting,
                    "PPh Badan dan pajak tangguhan tahun {$tahun}",
                    $lines
                );
                $perhitungan->update(['journal_id' => $jurnal->id]);
            }

            return $perhitungan->fresh('jurnal');
        });
    }

    public function sisaKerugian(int $tahunPakai): array
    {
        $terpakai = KompensasiKerugianFiskal::selectRaw('tahun_rugi, SUM(jumlah) as dipakai')
            ->groupBy('tahun_rugi')->pluck('dipakai', 'tahun_rugi');

        return PerhitunganPajakPenghasilan::where('laba_fiskal', '<', 0)
            ->where('tahun_pajak', '<', $tahunPakai)
            ->where('tahun_pajak', '>=', $tahunPakai - self::MASA_KOMPENSASI_TAHUN)
            ->orderBy('tahun_pajak')
            ->get(['tahun_pajak', 'laba_fiskal'])
            ->map(fn ($row) => [
                'tahun_rugi' => (int) $row->tahun_pajak,
                'kedaluwarsa_setelah' => (int) $row->tahun_pajak + self::MASA_KOMPENSASI_TAHUN,
                'sisa' => round(abs((float) $row->laba_fiskal) - (float) ($terpakai[$row->tahun_pajak] ?? 0), 2),
            ])
            ->filter(fn ($row) => $row['sisa'] > 0.005)
            ->values()->all();
    }

    private function pakaiKerugian(int $tahun, float $labaPositif): array
    {
        if ($labaPositif <= 0) {
            return [];
        }

        $dipakai = [];
        $sisaLaba = $labaPositif;
        foreach ($this->sisaKerugian($tahun) as $kerugian) {
            if ($sisaLaba <= 0.005) {
                break;
            }
            $ambil = round(min($sisaLaba, $kerugian['sisa']), 2);
            $dipakai[] = ['tahun_rugi' => $kerugian['tahun_rugi'], 'jumlah' => $ambil];
            $sisaLaba = round($sisaLaba - $ambil, 2);
        }

        return $dipakai;
    }

    private function pphTerutang(float $pkp, float $peredaranBruto): array
    {
        if ($pkp <= 0) {
            return [0.0, 0.0];
        }
        if ($peredaranBruto > self::BATAS_PEREDARAN) {
            return [0.0, round($pkp * self::TARIF, 2)];
        }

        $pkpFasilitas = $peredaranBruto > self::BATAS_FASILITAS
            ? round($pkp * self::BATAS_FASILITAS / $peredaranBruto, 2)
            : $pkp;
        $pkpNormal = round($pkp - $pkpFasilitas, 2);

        return [
            $pkpFasilitas,
            round($pkpFasilitas * self::TARIF * 0.5 + $pkpNormal * self::TARIF, 2),
        ];
    }

    private function bedaWaktuKumulatif(): float
    {
        return round(
            Aset::whereNotNull('kelompok_fiskal')->where('status', Aset::ACTIVE)->get()
                ->sum(fn (Aset $aset) => (float) $aset->book_value - $aset->nilaiBukuFiskal()),
            2
        );
    }

    private function saldoAkun(string $key, bool $normalDebit): float
    {
        $coaId = AccountingSetting::accountId($key);
        $sum = JurnalDetail::where('coa_id', $coaId)
            ->whereHas('jurnal', fn ($q) => $q->berlaku())
            ->selectRaw('SUM(debit) as debit, SUM(kredit) as kredit')->first();

        $debit = (float) ($sum->debit ?? 0);
        $kredit = (float) ($sum->kredit ?? 0);

        return round($normalDebit ? $debit - $kredit : $kredit - $debit, 2);
    }

    private function pajakTangguhanTercatat(): float
    {
        return round(
            $this->saldoAkun(AccountingSetting::LIABILITAS_PAJAK_TANGGUHAN, false)
                - $this->saldoAkun(AccountingSetting::ASET_PAJAK_TANGGUHAN, true),
            2
        );
    }

    private function barisJurnal(array $data): array
    {
        $lines = [];
        $pph = (float) $data['pph_terutang'];
        if ($pph > 0) {
            $lines[] = ['coa_id' => AccountingSetting::accountId(AccountingSetting::BEBAN_PPH_BADAN), 'debit' => $pph, 'kredit' => 0, 'keterangan' => 'Beban PPh Badan ' . $data['tahun_pajak']];
            $lines[] = ['coa_id' => AccountingSetting::accountId(AccountingSetting::HUTANG_PPH_BADAN), 'debit' => 0, 'kredit' => $pph, 'keterangan' => 'Hutang PPh Badan ' . $data['tahun_pajak']];
        }

        $seharusnya = (float) $data['pajak_tangguhan_seharusnya'];
        $dtlSekarang = $this->saldoAkun(AccountingSetting::LIABILITAS_PAJAK_TANGGUHAN, false);
        $dtaSekarang = $this->saldoAkun(AccountingSetting::ASET_PAJAK_TANGGUHAN, true);
        $deltaDtl = round(max($seharusnya, 0) - $dtlSekarang, 2);
        $deltaDta = round(max(-$seharusnya, 0) - $dtaSekarang, 2);
        $deltaBeban = round($deltaDtl - $deltaDta, 2);

        foreach ([
            [AccountingSetting::LIABILITAS_PAJAK_TANGGUHAN, $deltaDtl, false, 'Liabilitas pajak tangguhan'],
            [AccountingSetting::ASET_PAJAK_TANGGUHAN, $deltaDta, true, 'Aset pajak tangguhan'],
            [AccountingSetting::BEBAN_PAJAK_TANGGUHAN, $deltaBeban, true, 'Beban (manfaat) pajak tangguhan'],
        ] as [$key, $delta, $normalDebit, $keterangan]) {
            if (abs($delta) < 0.005) {
                continue;
            }
            $naik = $delta > 0;
            $lines[] = [
                'coa_id' => AccountingSetting::accountId($key),
                'debit' => $naik === $normalDebit ? abs($delta) : 0,
                'kredit' => $naik === $normalDebit ? 0 : abs($delta),
                'keterangan' => $keterangan,
            ];
        }

        return $lines;
    }
}
