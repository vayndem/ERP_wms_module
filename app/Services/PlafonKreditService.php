<?php

namespace App\Services;

use App\Models\FakturPenjualan;
use App\Models\Pelanggan;
use App\Models\PesananPenjualan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

class PlafonKreditService
{
    public function eksposur(Pelanggan $pelanggan, ?int $kecualiPesananId = null): array
    {
        $piutang = round((float) FakturPenjualan::where('pelanggan_id', $pelanggan->id)
            ->whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
            ->sum('sisa_tagihan'), 2);

        $terkirimBelumFaktur = round((float) DB::table('wms_surat_jalan_detail as d')
            ->join('wms_surat_jalan as sj', 'sj.id', '=', 'd.surat_jalan_id')
            ->join('wms_pesanan_penjualan as p', 'p.id', '=', 'sj.pesanan_penjualan_id')
            ->where('p.pelanggan_id', $pelanggan->id)
            ->where('sj.status', 'POSTED')
            ->when($kecualiPesananId, fn ($q) => $q->where('p.id', '!=', $kecualiPesananId))
            ->selectRaw('COALESCE(SUM(GREATEST(d.jumlah - d.jumlah_terfaktur, 0) * d.harga_satuan), 0) as nilai')
            ->value('nilai'), 2);

        $dipesanBelumKirim = round((float) DB::table('wms_pesanan_penjualan_detail as d')
            ->join('wms_pesanan_penjualan as p', 'p.id', '=', 'd.pesanan_penjualan_id')
            ->where('p.pelanggan_id', $pelanggan->id)
            ->where('p.status', PesananPenjualan::OPEN)
            ->when($kecualiPesananId, fn ($q) => $q->where('p.id', '!=', $kecualiPesananId))
            ->selectRaw('COALESCE(SUM(GREATEST(d.jumlah - d.jumlah_terkirim, 0) * d.harga_satuan), 0) as nilai')
            ->value('nilai'), 2);

        $total = round($piutang + $terkirimBelumFaktur + $dipesanBelumKirim, 2);
        $plafon = round((float) $pelanggan->plafon_kredit, 2);

        return [
            'pelanggan' => $pelanggan,
            'plafon' => $plafon,
            'tanpa_batas' => $plafon <= 0,
            'piutang' => $piutang,
            'terkirim_belum_faktur' => $terkirimBelumFaktur,
            'dipesan_belum_kirim' => $dipesanBelumKirim,
            'total' => $total,
            'sisa_plafon' => $plafon <= 0 ? null : round($plafon - $total, 2),
        ];
    }

    public function eksposurBanyak(iterable $pelanggan): array
    {
        $pelanggan = collect($pelanggan);
        $ids = $pelanggan->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        $piutang = FakturPenjualan::whereIn('pelanggan_id', $ids)
            ->whereNotIn('status', [FakturPenjualan::DRAFT, FakturPenjualan::VOID])
            ->groupBy('pelanggan_id')
            ->selectRaw('pelanggan_id, SUM(sisa_tagihan) as nilai')
            ->pluck('nilai', 'pelanggan_id');

        $terkirimBelumFaktur = DB::table('wms_surat_jalan_detail as d')
            ->join('wms_surat_jalan as sj', 'sj.id', '=', 'd.surat_jalan_id')
            ->join('wms_pesanan_penjualan as p', 'p.id', '=', 'sj.pesanan_penjualan_id')
            ->whereIn('p.pelanggan_id', $ids)
            ->where('sj.status', 'POSTED')
            ->groupBy('p.pelanggan_id')
            ->selectRaw('p.pelanggan_id as pelanggan_id, COALESCE(SUM(GREATEST(d.jumlah - d.jumlah_terfaktur, 0) * d.harga_satuan), 0) as nilai')
            ->pluck('nilai', 'pelanggan_id');

        $dipesanBelumKirim = DB::table('wms_pesanan_penjualan_detail as d')
            ->join('wms_pesanan_penjualan as p', 'p.id', '=', 'd.pesanan_penjualan_id')
            ->whereIn('p.pelanggan_id', $ids)
            ->where('p.status', PesananPenjualan::OPEN)
            ->groupBy('p.pelanggan_id')
            ->selectRaw('p.pelanggan_id as pelanggan_id, COALESCE(SUM(GREATEST(d.jumlah - d.jumlah_terkirim, 0) * d.harga_satuan), 0) as nilai')
            ->pluck('nilai', 'pelanggan_id');

        return $pelanggan->mapWithKeys(function (Pelanggan $baris) use ($piutang, $terkirimBelumFaktur, $dipesanBelumKirim) {
            $a = round((float) ($piutang[$baris->id] ?? 0), 2);
            $b = round((float) ($terkirimBelumFaktur[$baris->id] ?? 0), 2);
            $c = round((float) ($dipesanBelumKirim[$baris->id] ?? 0), 2);
            $total = round($a + $b + $c, 2);
            $plafon = round((float) $baris->plafon_kredit, 2);

            return [$baris->id => [
                'pelanggan' => $baris,
                'plafon' => $plafon,
                'tanpa_batas' => $plafon <= 0,
                'piutang' => $a,
                'terkirim_belum_faktur' => $b,
                'dipesan_belum_kirim' => $c,
                'total' => $total,
                'sisa_plafon' => $plafon <= 0 ? null : round($plafon - $total, 2),
            ]];
        })->all();
    }

    public function periksa(Pelanggan $pelanggan, float $nilaiPesananBaru, User $user, ?string $alasan = null): array
    {
        $eksposur = $this->eksposur($pelanggan);

        if ($eksposur['tanpa_batas']) {
            return $eksposur + ['dilampaui' => false];
        }

        $setelah = round($eksposur['total'] + $nilaiPesananBaru, 2);

        if ($setelah <= $eksposur['plafon'] + 0.005) {
            return $eksposur + ['dilampaui' => false];
        }

        $pesan = sprintf(
            'Pesanan ini membuat eksposur %s menjadi Rp %s, melewati plafon kredit Rp %s.',
            $pelanggan->nama,
            number_format($setelah, 2, ',', '.'),
            number_format($eksposur['plafon'], 2, ',', '.')
        );

        if (Gate::forUser($user)->denies('tembusPlafonKredit')) {
            throw new RuntimeException($pesan . ' Hanya Super Admin yang dapat menembus plafon, dengan alasan tertulis.');
        }

        if (trim((string) $alasan) === '') {
            throw new RuntimeException($pesan . ' Isi alasan penembusan plafon untuk melanjutkan.');
        }

        return $eksposur + ['dilampaui' => true, 'eksposur_setelah' => $setelah];
    }
}
