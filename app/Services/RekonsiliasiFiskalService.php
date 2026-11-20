<?php

namespace App\Services;

use App\Models\BaganAkun;
use App\Models\JurnalDetail;
use App\Models\PenyusutanAset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class RekonsiliasiFiskalService
{
    public function reconcile(Carbon $from, Carbon $to): array
    {
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        $labaKomersial = $this->labaKomersial($from, $to);
        $baris = $this->barisKoreksi($from, $to);

        $bedaTetapPositif = $baris->where('kelompok', 'BEDA_TETAP_POSITIF');
        $bedaTetapNegatif = $baris->where('kelompok', 'BEDA_TETAP_NEGATIF');
        $bedaWaktu = $baris->where('kelompok', 'BEDA_WAKTU');

        $koreksiPositif = round($bedaTetapPositif->sum('jumlah'), 2);
        $koreksiNegatif = round($bedaTetapNegatif->sum('jumlah'), 2);
        $koreksiBedaWaktu = round($bedaWaktu->sum('koreksi'), 2);

        return [
            'from' => $from,
            'to' => $to,
            'laba_komersial' => $labaKomersial,
            'beda_tetap_positif' => $bedaTetapPositif->values(),
            'beda_tetap_negatif' => $bedaTetapNegatif->values(),
            'beda_waktu' => $bedaWaktu->values(),
            'total_koreksi_positif' => $koreksiPositif,
            'total_koreksi_negatif' => $koreksiNegatif,
            'total_koreksi_beda_waktu' => $koreksiBedaWaktu,
            'laba_fiskal' => round($labaKomersial + $koreksiPositif - $koreksiNegatif + $koreksiBedaWaktu, 2),
        ];
    }

    private function labaKomersial(Carbon $from, Carbon $to): float
    {
        $sums = JurnalDetail::query()
            ->join('wms_bagan_akun', 'wms_bagan_akun.id', '=', 'wms_jurnal_detail.coa_id')
            ->whereHas('jurnal', fn ($q) => $q->berlaku()->whereBetween('tanggal', [$from, $to]))
            ->whereIn('wms_bagan_akun.kategori_akun', ['PENDAPATAN', 'BEBAN'])
            ->selectRaw('wms_bagan_akun.kategori_akun, SUM(wms_jurnal_detail.debit) as debit, SUM(wms_jurnal_detail.kredit) as kredit')
            ->groupBy('wms_bagan_akun.kategori_akun')
            ->get()->keyBy('kategori_akun');

        $pendapatan = (float) ($sums->get('PENDAPATAN')->kredit ?? 0) - (float) ($sums->get('PENDAPATAN')->debit ?? 0);
        $beban = (float) ($sums->get('BEBAN')->debit ?? 0) - (float) ($sums->get('BEBAN')->kredit ?? 0);

        return round($pendapatan - $beban, 2);
    }

    private function penyusutanPerAkunBeban(Carbon $from, Carbon $to): Collection
    {
        return PenyusutanAset::query()
            ->join('wms_asets', 'wms_asets.id', '=', 'wms_penyusutan_asets.aset_id')
            ->join('wms_kategori_asets', 'wms_kategori_asets.id', '=', 'wms_asets.kategori_aset_id')
            ->whereBetween('wms_penyusutan_asets.posting_date', [$from, $to])
            ->whereNotNull('wms_kategori_asets.depreciation_expense_coa_id')
            ->selectRaw('wms_kategori_asets.depreciation_expense_coa_id as coa_id')
            ->selectRaw('SUM(wms_penyusutan_asets.amount) as komersial, SUM(wms_penyusutan_asets.amount_fiskal) as fiskal')
            ->groupBy('wms_kategori_asets.depreciation_expense_coa_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->coa_id => [
                'komersial' => round((float) $row->komersial, 2),
                'fiskal' => round((float) $row->fiskal, 2),
                'koreksi' => round((float) $row->komersial - (float) $row->fiskal, 2),
            ]]);
    }

    private function barisKoreksi(Carbon $from, Carbon $to): Collection
    {
        $penyusutan = $this->penyusutanPerAkunBeban($from, $to);

        return JurnalDetail::query()
            ->join('wms_bagan_akun', 'wms_bagan_akun.id', '=', 'wms_jurnal_detail.coa_id')
            ->whereHas('jurnal', fn ($q) => $q->berlaku()->whereBetween('tanggal', [$from, $to]))
            ->whereRaw("COALESCE(wms_jurnal_detail.klasifikasi_fiskal, wms_bagan_akun.klasifikasi_fiskal) <> ?", [BaganAkun::FISKAL_NONE])
            ->selectRaw('wms_bagan_akun.id as coa_id, wms_bagan_akun.kode_akun, wms_bagan_akun.nama_akun, wms_bagan_akun.kategori_akun')
            ->selectRaw('COALESCE(wms_jurnal_detail.klasifikasi_fiskal, wms_bagan_akun.klasifikasi_fiskal) as klasifikasi')
            ->selectRaw('SUM(wms_jurnal_detail.debit) as debit, SUM(wms_jurnal_detail.kredit) as kredit')
            ->groupBy('wms_bagan_akun.id', 'wms_bagan_akun.kode_akun', 'wms_bagan_akun.nama_akun', 'wms_bagan_akun.kategori_akun', 'klasifikasi')
            ->get()
            ->map(function ($row) use ($penyusutan) {
                $jumlah = $row->kategori_akun === 'BEBAN'
                    ? (float) $row->debit - (float) $row->kredit
                    : (float) $row->kredit - (float) $row->debit;

                $kelompok = match (true) {
                    $row->klasifikasi === BaganAkun::FISKAL_BEDA_WAKTU => 'BEDA_WAKTU',
                    $row->klasifikasi === BaganAkun::FISKAL_PENGHASILAN_FINAL => 'BEDA_TETAP_NEGATIF',
                    $row->kategori_akun === 'BEBAN' => 'BEDA_TETAP_POSITIF',
                    default => 'BEDA_TETAP_NEGATIF',
                };

                $dataPenyusutan = $kelompok === 'BEDA_WAKTU' ? $penyusutan->get((int) $row->coa_id) : null;

                return [
                    'coa_id' => (int) $row->coa_id,
                    'kode_akun' => $row->kode_akun,
                    'nama_akun' => $row->nama_akun,
                    'kategori_akun' => $row->kategori_akun,
                    'klasifikasi' => $row->klasifikasi,
                    'kelompok' => $kelompok,
                    'jumlah' => round($jumlah, 2),
                    'nilai_fiskal' => $dataPenyusutan['fiskal'] ?? null,
                    'koreksi' => match (true) {
                        $dataPenyusutan !== null => $dataPenyusutan['koreksi'],
                        $kelompok === 'BEDA_WAKTU' => 0.0,
                        default => round($jumlah, 2),
                    },
                    'sumber_koreksi' => match (true) {
                        $dataPenyusutan !== null => 'JADWAL_PENYUSUTAN_FISKAL',
                        $kelompok === 'BEDA_WAKTU' => 'MENUNGGU_JADWAL_FISKAL',
                        default => 'NILAI_BUKU',
                    },
                ];
            })
            ->filter(fn ($row) => abs($row['jumlah']) >= 0.005)
            ->sortBy('kode_akun')
            ->values();
    }
}
