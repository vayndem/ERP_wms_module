<?php

namespace App\Services;

use App\Models\FakturPenjualan;
use App\Models\Pelanggan;
use App\Models\PesananPenjualan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

        if (!$user->isSuperAdmin()) {
            throw new RuntimeException($pesan . ' Hanya Super Admin yang dapat menembus plafon, dengan alasan tertulis.');
        }

        if (trim((string) $alasan) === '') {
            throw new RuntimeException($pesan . ' Isi alasan penembusan plafon untuk melanjutkan.');
        }

        return $eksposur + ['dilampaui' => true, 'eksposur_setelah' => $setelah];
    }
}
