<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\FakturPenjualan;
use App\Models\JurnalDetail;
use App\Models\PenerimaanPembayaran;
use App\Models\ReturPenjualan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class PiutangAgingService
{
    public const BELUM_JATUH_TEMPO = 'Belum Jatuh Tempo';

    public const EMBER = [
        self::BELUM_JATUH_TEMPO,
        '1-30 Hari',
        '31-60 Hari',
        '61-90 Hari',
        '> 90 Hari',
    ];

    public function laporan(?Carbon $perTanggal = null, ?int $pelangganId = null): array
    {
        $perTanggal = ($perTanggal ?? now())->copy()->startOfDay();

        $baris = $this->barisFaktur($perTanggal, $pelangganId);

        return [
            'per_tanggal' => $perTanggal,
            'baris' => $baris,
            'per_pelanggan' => $this->ringkasPerPelanggan($baris),
            'total' => $this->ringkasEmber($baris),
            'total_piutang' => round($baris->sum('sisa'), 2),
            'tie_out' => $this->tieOut($baris, $perTanggal, $pelangganId),
        ];
    }

    private function barisFaktur(Carbon $perTanggal, ?int $pelangganId): Collection
    {
        $faktur = FakturPenjualan::with('pelanggan')
            ->whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
            ->where('tanggal', '<=', $perTanggal->toDateString())
            ->when($pelangganId, fn ($query) => $query->where('pelanggan_id', $pelangganId))
            ->orderBy('tanggal')
            ->get();

        if ($faktur->isEmpty()) {
            return collect();
        }

        $ids = $faktur->pluck('id');

        $dibayar = PenerimaanPembayaran::whereIn('faktur_penjualan_id', $ids)
            ->where('status', PenerimaanPembayaran::POSTED)
            ->where('tanggal', '<=', $perTanggal->toDateString())
            ->selectRaw('faktur_penjualan_id, SUM(jumlah) as total')
            ->groupBy('faktur_penjualan_id')
            ->pluck('total', 'faktur_penjualan_id');

        $returSesudah = ReturPenjualan::whereIn('faktur_penjualan_id', $ids)
            ->where('status', ReturPenjualan::POSTED)
            ->where('tanggal', '>', $perTanggal->toDateString())
            ->selectRaw('faktur_penjualan_id, SUM(total_dpp + total_ppn) as total')
            ->groupBy('faktur_penjualan_id')
            ->pluck('total', 'faktur_penjualan_id');

        return $faktur
            ->map(function (FakturPenjualan $item) use ($dibayar, $returSesudah, $perTanggal) {
                $tagihan = (float) $item->grand_total + (float) ($returSesudah[$item->id] ?? 0);
                $sisa = round($tagihan - (float) ($dibayar[$item->id] ?? 0), 2);

                if ($sisa <= 0.005) {
                    return null;
                }

                $jatuhTempo = $item->jatuh_tempo ? Carbon::parse($item->jatuh_tempo)->startOfDay() : null;
                $lewat = $jatuhTempo && $jatuhTempo->lessThan($perTanggal)
                    ? (int) $jatuhTempo->diffInDays($perTanggal)
                    : 0;

                return [
                    'faktur_id' => $item->id,
                    'nomor' => $item->nomor,
                    'tanggal' => $item->tanggal,
                    'jatuh_tempo' => $jatuhTempo,
                    'pelanggan_id' => $item->pelanggan_id,
                    'pelanggan' => $item->pelanggan?->nama ?? '-',
                    'tagihan' => round($tagihan, 2),
                    'dibayar' => round((float) ($dibayar[$item->id] ?? 0), 2),
                    'sisa' => $sisa,
                    'hari_lewat' => $lewat,
                    'ember' => $this->ember($lewat),
                ];
            })
            ->filter()
            ->sortBy([['pelanggan', 'asc'], ['jatuh_tempo', 'asc']])
            ->values();
    }

    private function ember(int $hariLewat): string
    {
        return match (true) {
            $hariLewat <= 0 => self::BELUM_JATUH_TEMPO,
            $hariLewat <= 30 => '1-30 Hari',
            $hariLewat <= 60 => '31-60 Hari',
            $hariLewat <= 90 => '61-90 Hari',
            default => '> 90 Hari',
        };
    }

    private function ringkasPerPelanggan(Collection $baris): Collection
    {
        return $baris
            ->groupBy('pelanggan_id')
            ->map(function (Collection $grup) {
                $ember = $this->ringkasEmber($grup);

                return [
                    'pelanggan_id' => $grup->first()['pelanggan_id'],
                    'pelanggan' => $grup->first()['pelanggan'],
                    'jumlah_faktur' => $grup->count(),
                    'ember' => $ember,
                    'total' => round($grup->sum('sisa'), 2),
                    'jatuh_tempo_terlama' => (int) $grup->max('hari_lewat'),
                ];
            })
            ->sortByDesc('total')
            ->values();
    }

    private function ringkasEmber(Collection $baris): array
    {
        $ember = array_fill_keys(self::EMBER, 0.0);

        foreach ($baris as $item) {
            $ember[$item['ember']] += $item['sisa'];
        }

        return array_map(fn ($nilai) => round($nilai, 2), $ember);
    }

    private function tieOut(Collection $baris, Carbon $perTanggal, ?int $pelangganId): array
    {
        if ($pelangganId) {
            return ['tersedia' => false, 'alasan' => 'Tie-out buku besar hanya tersedia untuk seluruh pelanggan.'];
        }

        try {
            $coaId = AccountingSetting::accountId(AccountingSetting::PIUTANG_USAHA);
        } catch (RuntimeException $e) {
            return ['tersedia' => false, 'alasan' => $e->getMessage()];
        }

        $saldoGl = round((float) JurnalDetail::where('coa_id', $coaId)
            ->whereHas('jurnal', fn ($query) => $query
                ->berlaku()
                ->where('tanggal', '<=', $perTanggal->toDateString()))
            ->selectRaw('SUM(debit - kredit) as saldo')
            ->value('saldo'), 2);

        $subledger = round($baris->sum('sisa'), 2);

        return [
            'tersedia' => true,
            'saldo_gl' => $saldoGl,
            'subledger' => $subledger,
            'selisih' => round($subledger - $saldoGl, 2),
        ];
    }
}
