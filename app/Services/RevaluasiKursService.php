<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\FakturPembelian;
use App\Models\KursPenutup;
use App\Models\RevaluasiKurs;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RevaluasiKursService
{
    public function __construct(
        private WmsAccountingService $akuntansi,
        private AccountingPeriodService $periods,
    ) {}

    public function kursPenutup(string $periode): Collection
    {
        return KursPenutup::where('periode', $periode)->get()->keyBy('mata_uang');
    }

    public function simpanKurs(array $data, User $user): KursPenutup
    {
        return KursPenutup::updateOrCreate(
            ['mata_uang' => strtoupper($data['mata_uang']), 'periode' => $data['periode']],
            ['kurs' => round((float) $data['kurs'], 4), 'sumber' => $data['sumber'] ?? null, 'dibuat_oleh' => $user->id]
        );
    }

    public function hitung(string $periode): array
    {
        $kurs = $this->kursPenutup($periode);
        $sudah = RevaluasiKurs::where('periode', $periode)->exists();

        $baris = $this->fakturValasTerbuka()
            ->map(function (FakturPembelian $faktur) use ($kurs, $periode) {
                $mataUang = strtoupper((string) $faktur->mata_uang_asing);
                $kursTransaksi = (float) $faktur->kurs;

                if ($kursTransaksi <= 0) {
                    return null;
                }

                $valas = round((float) $faktur->sisa_tagihan / $kursTransaksi, 2);
                $akumulasi = $this->akumulasiSelisih((int) $faktur->id, $periode);
                $nilaiLama = round((float) $faktur->sisa_tagihan + $akumulasi, 2);
                $kursBaru = $kurs->has($mataUang) ? (float) $kurs[$mataUang]->kurs : null;

                if ($kursBaru === null) {
                    return [
                        'faktur' => $faktur,
                        'mata_uang' => $mataUang,
                        'valas_beredar' => $valas,
                        'kurs_lama' => $kursTransaksi,
                        'kurs_baru' => null,
                        'nilai_lama' => $nilaiLama,
                        'nilai_baru' => null,
                        'selisih' => null,
                        'catatan' => "Kurs penutup {$mataUang} periode {$periode} belum diisi.",
                    ];
                }

                $nilaiBaru = round($valas * $kursBaru, 2);

                return [
                    'faktur' => $faktur,
                    'mata_uang' => $mataUang,
                    'valas_beredar' => $valas,
                    'kurs_lama' => $kursTransaksi,
                    'kurs_baru' => $kursBaru,
                    'nilai_lama' => $nilaiLama,
                    'nilai_baru' => $nilaiBaru,
                    'selisih' => round($nilaiBaru - $nilaiLama, 2),
                    'catatan' => null,
                ];
            })
            ->filter()
            ->values();

        return [
            'periode' => $periode,
            'baris' => $baris,
            'total_selisih' => round($baris->sum(fn ($b) => $b['selisih'] ?? 0), 2),
            'siap' => $baris->isNotEmpty() && $baris->every(fn ($b) => $b['kurs_baru'] !== null),
            'sudah_diposting' => $sudah,
        ];
    }

    public function posting(string $periode, User $user)
    {
        return DB::transaction(function () use ($periode, $user) {
            if (RevaluasiKurs::where('periode', $periode)->exists()) {
                throw new RuntimeException("Revaluasi kurs periode {$periode} sudah pernah diposting.");
            }

            $hasil = $this->hitung($periode);

            if ($hasil['baris']->isEmpty()) {
                throw new RuntimeException('Tidak ada tagihan mata uang asing yang masih terbuka pada periode ini.');
            }

            if (!$hasil['siap']) {
                throw new RuntimeException('Masih ada mata uang yang kurs penutupnya belum diisi.');
            }

            $tanggal = $this->akhirPeriode($periode);
            $this->periods->assertOpen($tanggal, 'Revaluasi kurs');

            $jurnal = $this->akuntansi->postRevaluasiKurs($periode, $tanggal, $hasil['total_selisih']);

            foreach ($hasil['baris'] as $baris) {
                RevaluasiKurs::create([
                    'periode' => $periode,
                    'tanggal' => $tanggal,
                    'faktur_pembelian_id' => $baris['faktur']->id,
                    'mata_uang' => $baris['mata_uang'],
                    'valas_beredar' => $baris['valas_beredar'],
                    'kurs_lama' => $baris['kurs_lama'],
                    'kurs_baru' => $baris['kurs_baru'],
                    'nilai_lama' => $baris['nilai_lama'],
                    'nilai_baru' => $baris['nilai_baru'],
                    'selisih' => $baris['selisih'],
                    'journal_id' => $jurnal?->id,
                    'dibuat_oleh' => $user->id,
                ]);
            }

            return $hasil;
        });
    }

    public function akumulasiSeluruh(): float
    {
        return round((float) RevaluasiKurs::sum('selisih'), 2);
    }

    private function akumulasiSelisih(int $fakturId, string $periodeSampai): float
    {
        return round((float) RevaluasiKurs::where('faktur_pembelian_id', $fakturId)
            ->where('periode', '<', $periodeSampai)
            ->sum('selisih'), 2);
    }

    private function fakturValasTerbuka(): Collection
    {
        return FakturPembelian::whereNotNull('mata_uang_asing')
            ->whereNotNull('kurs')
            ->where('sisa_tagihan', '>', 0)
            ->whereNotIn('status', [FakturPembelian::VOID, FakturPembelian::PENDING_APPROVAL])
            ->orderBy('no_invoice')
            ->get();
    }

    private function akhirPeriode(string $periode): string
    {
        return \Illuminate\Support\Carbon::createFromFormat('Y-m', $periode)->endOfMonth()->toDateString();
    }
}
