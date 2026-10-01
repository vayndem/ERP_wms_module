<?php

namespace App\Services;

use App\Models\CrossDock;
use App\Models\PenerimaanBarang;
use App\Models\PenerimaanBarangDetail;
use App\Models\PesananPenjualan;
use App\Models\PesananPenjualanDetail;
use App\Models\ReservasiPersediaan;
use App\Models\StokGudang;
use App\Models\SuratJalan;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CrossDockService
{
    public const HARI_TERAKHIR = 14;

    public function __construct(
        private WarehouseExecutionService $eksekusi,
        private StokGudangService $stok,
        private DocumentNumberService $numbers,
    ) {}

    public function saran(?int $gudangId = null, ?array $gudangDiizinkan = null, int $hariTerakhir = self::HARI_TERAKHIR): Collection
    {
        if ($gudangDiizinkan !== null && $gudangDiizinkan === []) {
            return collect();
        }

        $batas = now()->subDays($hariTerakhir)->startOfDay();

        $pesanan = PesananPenjualanDetail::with(['bahan', 'pesanan.pelanggan'])
            ->whereHas('pesanan', fn ($query) => $query
                ->where('status', PesananPenjualan::OPEN)
                ->when($gudangId, fn ($q) => $q->where('gudang_id', $gudangId))
                ->when($gudangDiizinkan !== null, fn ($q) => $q->whereIn('gudang_id', $gudangDiizinkan)))
            ->get()
            ->filter(fn (PesananPenjualanDetail $detail) => $detail->sisaKirim() > 0.000001);

        if ($pesanan->isEmpty()) {
            return collect();
        }

        $penerimaan = $this->penerimaanTerkini($pesanan->pluck('bahan_id')->unique(), $batas);
        $terpakai = $this->jumlahTerpakaiPerDetail($penerimaan->pluck('detail_id'));

        return $pesanan
            ->flatMap(function (PesananPenjualanDetail $detail) use ($penerimaan, $terpakai) {
                $gudang = (int) $detail->pesanan->gudang_id;
                $sisaPesanan = round($detail->sisaKirim() - $this->jumlahAktifPesanan((int) $detail->id), 6);

                if ($sisaPesanan <= 0.000001) {
                    return collect();
                }

                $bebas = $this->stokBebas($gudang, (int) $detail->bahan_id);

                return $penerimaan
                    ->where('bahan_id', (int) $detail->bahan_id)
                    ->where('gudang_id', $gudang)
                    ->map(function ($baris) use ($detail, $sisaPesanan, $bebas, $terpakai) {
                        $sisaLpb = round((float) $baris->jumlah - (float) ($terpakai[$baris->detail_id] ?? 0), 6);
                        $usul = round(min($sisaPesanan, $sisaLpb, $bebas), 6);

                        if ($usul <= 0.000001) {
                            return null;
                        }

                        return [
                            'penerimaan_barang_detail_id' => (int) $baris->detail_id,
                            'pesanan_penjualan_detail_id' => (int) $detail->id,
                            'id_lpb' => $baris->id_lpb,
                            'tanggal_terima' => $baris->tanggal,
                            'pesanan' => $detail->pesanan->nomor,
                            'pelanggan' => $detail->pesanan->pelanggan?->nama ?? '-',
                            'bahan' => $detail->bahan?->nama ?? '-',
                            'bahan_id' => (int) $detail->bahan_id,
                            'gudang_id' => (int) $detail->pesanan->gudang_id,
                            'sisa_pesanan' => $sisaPesanan,
                            'sisa_penerimaan' => $sisaLpb,
                            'stok_bebas' => round($bebas, 6),
                            'usul' => $usul,
                        ];
                    })
                    ->filter()
                    ->values();
            })
            ->sortBy('tanggal_terima')
            ->values();
    }

    public function tandai(array $data, User $user): CrossDock
    {
        return DB::transaction(function () use ($data, $user) {
            $penerimaanDetail = PenerimaanBarangDetail::lockForUpdate()
                ->findOrFail($data['penerimaan_barang_detail_id']);

            $pesananDetail = PesananPenjualanDetail::with('pesanan')
                ->lockForUpdate()
                ->findOrFail($data['pesanan_penjualan_detail_id']);

            $lpb = PenerimaanBarang::where('id_lpb', $penerimaanDetail->id_lpb)->firstOrFail();
            $jumlah = round((float) $data['jumlah'], 6);

            if ($jumlah <= 0.000001) {
                throw new RuntimeException('Jumlah cross dock harus lebih besar dari nol.');
            }

            if ($lpb->status !== PenerimaanBarang::POSTED) {
                throw new RuntimeException('Hanya penerimaan barang yang sudah diposting yang dapat di-cross dock.');
            }

            if ((int) $penerimaanDetail->id_bahan !== (int) $pesananDetail->bahan_id) {
                throw new RuntimeException('Bahan pada penerimaan dan pesanan penjualan tidak sama.');
            }

            if (!$pesananDetail->pesanan || !$pesananDetail->pesanan->isOpen()) {
                throw new RuntimeException('Pesanan penjualan sudah tidak terbuka.');
            }

            if ((int) $lpb->gudang_id !== (int) $pesananDetail->pesanan->gudang_id) {
                throw new RuntimeException('Gudang penerimaan berbeda dengan gudang pengiriman pesanan.');
            }

            if (!$user->canAccessGudang((int) $lpb->gudang_id, 'receive')) {
                throw new RuntimeException('Anda tidak punya akses ke gudang penerimaan ini.');
            }

            $sisaPesanan = $pesananDetail->sisaKirim() - $this->jumlahAktifPesanan($pesananDetail->id);

            if ($jumlah > $sisaPesanan + 0.000001) {
                throw new RuntimeException('Jumlah melebihi sisa pesanan yang belum terikat surat jalan atau cross dock.');
            }

            $sisaLpb = (float) $penerimaanDetail->jumlah_barang_diterima
                - (float) ($this->jumlahTerpakaiPerDetail(collect([$penerimaanDetail->id]))[$penerimaanDetail->id] ?? 0);

            if ($jumlah > $sisaLpb + 0.000001) {
                throw new RuntimeException('Jumlah melebihi sisa baris penerimaan yang belum di-cross dock.');
            }

            $crossDock = CrossDock::create([
                'nomor' => $this->numbers->internal('XDK', 'STK'),
                'tanggal' => now()->toDateString(),
                'penerimaan_barang_detail_id' => $penerimaanDetail->id,
                'pesanan_penjualan_detail_id' => $pesananDetail->id,
                'gudang_id' => (int) $lpb->gudang_id,
                'bahan_id' => (int) $penerimaanDetail->id_bahan,
                'jumlah' => $jumlah,
                'status' => CrossDock::DIRESERVASI,
                'keterangan' => $data['keterangan'] ?? null,
                'dibuat_oleh' => $user->id,
            ]);

            $reservasi = $this->eksekusi->reserve(
                (int) $lpb->gudang_id,
                (int) $penerimaanDetail->id_bahan,
                $jumlah,
                'CROSS_DOCK',
                (int) $crossDock->id,
                (int) $user->id
            );

            $crossDock->update(['reservasi_id' => $reservasi->id]);

            return $crossDock->fresh(['bahan', 'gudang', 'pesananDetail.pesanan']);
        });
    }

    public function batalkan(CrossDock $crossDock): CrossDock
    {
        return DB::transaction(function () use ($crossDock) {
            $crossDock = CrossDock::lockForUpdate()->findOrFail($crossDock->id);

            if (!$crossDock->isAktif()) {
                throw new RuntimeException('Hanya cross dock berstatus DIRESERVASI yang dapat dibatalkan.');
            }

            $this->lepaskanReservasi($crossDock);

            $crossDock->update(['status' => CrossDock::DIBATALKAN, 'reservasi_id' => null]);

            return $crossDock->fresh();
        });
    }

    public function lepaskanUntukPengiriman(SuratJalan $suratJalan): int
    {
        $suratJalan->loadMissing('details');

        $dikirimPerBaris = $suratJalan->details
            ->groupBy('pesanan_penjualan_detail_id')
            ->map(fn ($grup) => round((float) $grup->sum('jumlah'), 6));

        $dilepas = 0;

        foreach ($dikirimPerBaris as $pesananDetailId => $jumlahKirim) {
            if (!$pesananDetailId) {
                continue;
            }

            $sisa = $jumlahKirim;

            $aktif = CrossDock::where('pesanan_penjualan_detail_id', $pesananDetailId)
                ->where('status', CrossDock::DIRESERVASI)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($aktif as $crossDock) {
                if ($sisa <= 0.000001) {
                    break;
                }

                $ambil = round(min((float) $crossDock->jumlah, $sisa), 6);
                $sisaTanda = round((float) $crossDock->jumlah - $ambil, 6);

                $this->lepaskanReservasi($crossDock);

                $crossDock->update([
                    'jumlah' => $ambil,
                    'status' => CrossDock::DIKIRIM,
                    'reservasi_id' => null,
                    'dikirim_pada' => now(),
                ]);

                if ($sisaTanda > 0.000001) {
                    $lanjutan = CrossDock::create([
                        'nomor' => $this->numbers->internal('XDK', 'STK'),
                        'tanggal' => now()->toDateString(),
                        'penerimaan_barang_detail_id' => $crossDock->penerimaan_barang_detail_id,
                        'pesanan_penjualan_detail_id' => $crossDock->pesanan_penjualan_detail_id,
                        'gudang_id' => $crossDock->gudang_id,
                        'bahan_id' => $crossDock->bahan_id,
                        'jumlah' => $sisaTanda,
                        'status' => CrossDock::DIRESERVASI,
                        'keterangan' => $crossDock->keterangan,
                        'dibuat_oleh' => $crossDock->dibuat_oleh,
                    ]);

                    $reservasi = $this->eksekusi->reserve(
                        (int) $crossDock->gudang_id,
                        (int) $crossDock->bahan_id,
                        $sisaTanda,
                        'CROSS_DOCK',
                        (int) $lanjutan->id,
                        $crossDock->dibuat_oleh ? (int) $crossDock->dibuat_oleh : null
                    );

                    $lanjutan->update(['reservasi_id' => $reservasi->id]);
                }

                $sisa = round($sisa - $ambil, 6);
                $dilepas++;
            }
        }

        return $dilepas;
    }

    private function stokBebas(int $gudangId, int $bahanId): float
    {
        $saldo = StokGudang::where('gudang_id', $gudangId)
            ->where('bahan_id', $bahanId)
            ->first();

        return $saldo ? (float) $saldo->stok_dapat_dipakai : 0.0;
    }

    private function lepaskanReservasi(CrossDock $crossDock): void
    {
        if (!$crossDock->reservasi_id) {
            return;
        }

        $reservasi = ReservasiPersediaan::find($crossDock->reservasi_id);

        if ($reservasi && in_array($reservasi->status, ['ACTIVE', 'PICKING', 'PICKED'], true)) {
            $this->eksekusi->release($reservasi);
        }
    }

    private function penerimaanTerkini(Collection $bahanIds, $batas): Collection
    {
        return DB::table('wms_penerimaan_barang_detail as d')
            ->join('wms_penerimaan_barang as l', 'l.id_lpb', '=', 'd.id_lpb')
            ->where('l.document_type', 'GOODS')
            ->where('l.status', PenerimaanBarang::POSTED)
            ->whereDate('l.tanggal', '>=', $batas)
            ->whereIn('d.id_bahan', $bahanIds)
            ->select([
                'd.id as detail_id',
                'd.id_bahan as bahan_id',
                'd.jumlah_barang_diterima as jumlah',
                'l.id_lpb as id_lpb',
                'l.gudang_id as gudang_id',
                'l.tanggal as tanggal',
            ])
            ->get()
            ->map(fn ($baris) => (object) [
                'detail_id' => (int) $baris->detail_id,
                'bahan_id' => (int) $baris->bahan_id,
                'jumlah' => (float) $baris->jumlah,
                'id_lpb' => $baris->id_lpb,
                'gudang_id' => (int) $baris->gudang_id,
                'tanggal' => $baris->tanggal,
            ]);
    }

    private function jumlahTerpakaiPerDetail(Collection $detailIds): array
    {
        if ($detailIds->isEmpty()) {
            return [];
        }

        return CrossDock::whereIn('penerimaan_barang_detail_id', $detailIds)
            ->where('status', '!=', CrossDock::DIBATALKAN)
            ->selectRaw('penerimaan_barang_detail_id, SUM(jumlah) as total')
            ->groupBy('penerimaan_barang_detail_id')
            ->pluck('total', 'penerimaan_barang_detail_id')
            ->map(fn ($nilai) => (float) $nilai)
            ->all();
    }

    private function jumlahAktifPesanan(int $pesananDetailId): float
    {
        return round((float) CrossDock::where('pesanan_penjualan_detail_id', $pesananDetailId)
            ->where('status', CrossDock::DIRESERVASI)
            ->sum('jumlah'), 6);
    }
}
