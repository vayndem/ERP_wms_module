<?php

namespace App\Services;

use App\Models\CrossDock;
use App\Models\DataPesanan;
use App\Models\FakturPembelian;
use App\Models\FakturPenjualan;
use App\Models\LayerPersediaan;
use App\Models\MaterialRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PengingatService
{
    public const AMBANG_KEDALUWARSA_HARI = 30;
    public const AMBANG_JATUH_TEMPO_HARI = 14;
    public const AMBANG_TRANSFER_MENGGANTUNG_HARI = 3;
    public const AMBANG_CROSS_DOCK_BASI_HARI = 7;
    public const AMBANG_PERINTAH_KERJA_MANDEK_HARI = 14;

    public function __construct(private PengeluaranBarangService $pengeluaran,
        private SubkontrakService $subkontrak,
    ) {}

    public function susun(string $konteks, string $label, mixed $tanggal, string $url, ?float $nilai = null): ?array
    {
        if (!$tanggal) {
            return null;
        }

        $tanggal = Carbon::parse($tanggal)->startOfDay();

        return [
            'konteks' => $konteks,
            'label' => $label,
            'tanggal' => $tanggal,
            'hari' => (int) today()->diffInDays($tanggal, false),
            'url' => $url,
            'nilai' => $nilai,
        ];
    }

    public function urutkan(array $items): Collection
    {
        return collect($items)->filter()->sortBy('hari')->values();
    }

    public function invoiceJatuhTempo(int $limit = 6): Collection
    {
        $rows = FakturPembelian::with('supplier')
            ->whereNotIn('status', [FakturPembelian::VOID, FakturPembelian::PENDING_APPROVAL])
            ->where('sisa_tagihan', '>', 0)
            ->whereNotNull('tgl_deadline_pembayaran')
            ->whereDate('tgl_deadline_pembayaran', '<=', today()->addDays(self::AMBANG_JATUH_TEMPO_HARI))
            ->orderBy('tgl_deadline_pembayaran')
            ->limit($limit)->get();

        return $this->urutkan($rows->map(fn ($inv) => $this->susun(
            'Invoice jatuh tempo',
            $inv->no_invoice . ' · ' . ($inv->supplier->nama ?? '-'),
            $inv->tgl_deadline_pembayaran,
            route('faktur-pembelian.index'),
            (float) $inv->sisa_tagihan,
        ))->all());
    }

    public function lotSegeraKedaluwarsa(?array $warehouseIds = null, int $limit = 5): Collection
    {
        $rows = LayerPersediaan::with(['lot.bahan', 'gudang'])
            ->where('remaining_quantity', '>', 0)
            ->where('stock_status', 'AVAILABLE')
            ->when($warehouseIds !== null, fn ($query) => $query->whereIn('gudang_id', $warehouseIds))
            ->whereHas('lot', fn ($lot) => $lot->whereNotNull('expires_at')
                ->whereDate('expires_at', '<=', today()->addDays(self::AMBANG_KEDALUWARSA_HARI)))
            ->get()
            ->groupBy('inventory_lot_id')
            ->map(fn ($layers) => [
                'lot' => $layers->first()->lot,
                'gudang' => $layers->first()->gudang,
                'sisa' => (float) $layers->sum('remaining_quantity'),
            ])
            ->sortBy(fn ($row) => $row['lot']->expires_at)
            ->take($limit);

        return $this->urutkan($rows->map(fn ($row) => $this->susun(
            'Lot kedaluwarsa',
            ($row['lot']->bahan->nama ?? 'Bahan') . ' · lot ' . $row['lot']->lot_number . ' · ' . ($row['gudang']->nama ?? '-'),
            $row['lot']->expires_at,
            route('wms-control.index'),
            $row['sisa'],
        ))->all());
    }

    public function transferMenggantung(?array $warehouseIds = null, int $limit = 5): Collection
    {
        $rows = DB::table('transfer_gudangs as tg')
            ->leftJoin('gudangs as asal', 'asal.id', '=', 'tg.gudang_asal_id')
            ->leftJoin('gudangs as tujuan', 'tujuan.id', '=', 'tg.gudang_tujuan_id')
            ->where('tg.status', 'DIKIRIM')
            ->when($warehouseIds !== null, fn ($query) => $query->where(fn ($q) => $q
                ->whereIn('tg.gudang_asal_id', $warehouseIds)->orWhereIn('tg.gudang_tujuan_id', $warehouseIds)))
            ->orderBy('tg.dikirim_pada')
            ->limit($limit)
            ->get(['tg.nomor_transfer', 'tg.dikirim_pada', 'tg.tanggal', 'asal.nama as asal_nama', 'tujuan.nama as tujuan_nama']);

        return $this->urutkan($rows->map(fn ($row) => $this->susun(
            'Transfer belum diterima',
            $row->nomor_transfer . ' · ' . ($row->asal_nama ?? '-') . ' → ' . ($row->tujuan_nama ?? '-'),
            $row->dikirim_pada ?: $row->tanggal,
            route('transfer-gudangs.index'),
        ))->all());
    }

    public function transferTerlaluLamaMenggantung(int $limit = 20): Collection
    {
        return $this->transferMenggantung(null, $limit)
            ->filter(fn ($row) => $row['hari'] <= -self::AMBANG_TRANSFER_MENGGANTUNG_HARI)
            ->values();
    }

    public function invoiceMenungguPersetujuan(int $limit = 20): Collection
    {
        $rows = FakturPembelian::with('supplier')
            ->where('status', FakturPembelian::PENDING_APPROVAL)
            ->orderBy('tanggal')
            ->limit($limit)->get();

        return $this->urutkan($rows->map(fn ($inv) => $this->susun(
            'Faktur menunggu persetujuan',
            $inv->no_invoice . ' · ' . ($inv->supplier->nama ?? '-'),
            $inv->tgl_deadline_pembayaran ?: $inv->tanggal,
            route('faktur-pembelian.index'),
            (float) $inv->grand_total,
        ))->all());
    }

    public function requestMenungguPersetujuan(int $limit = 20): Collection
    {
        $rows = MaterialRequest::where('status', MaterialRequest::PENDING)
            ->orderBy('created_at')
            ->limit($limit)->get();

        return $this->urutkan($rows->map(fn ($req) => $this->susun(
            'Request menunggu persetujuan',
            'Request ' . ($req->no_request ?? $req->id),
            $req->created_at,
            route('request.index'),
        ))->all());
    }

    public function piutangJatuhTempo(int $limit = 6): Collection
    {
        $rows = FakturPenjualan::with('pelanggan')
            ->whereIn('status', [FakturPenjualan::POSTED, FakturPenjualan::PARTIALLY_PAID])
            ->where('sisa_tagihan', '>', 0)
            ->whereNotNull('jatuh_tempo')
            ->whereDate('jatuh_tempo', '<=', today()->addDays(self::AMBANG_JATUH_TEMPO_HARI))
            ->orderBy('jatuh_tempo')
            ->limit($limit)->get();

        return $this->urutkan($rows->map(fn ($faktur) => $this->susun(
            'Piutang jatuh tempo',
            $faktur->nomor . ' · ' . ($faktur->pelanggan->nama ?? '-'),
            $faktur->jatuh_tempo,
            route('faktur-penjualan.index'),
            (float) $faktur->sisa_tagihan,
        ))->all());
    }

    public function barangKeluarBelumKembali(int $limit = 20): Collection
    {
        $terlambat = $this->pengeluaran->terlambat();

        $keluar = $terlambat['keluar']->map(fn ($row) => $this->susun(
            'Barang keluar belum kembali',
            $row->nomor . ' · ' . ($row->supplier->nama ?? '-'),
            $row->estimasi_kembali,
            route('pengeluaran-barang.index'),
        ));

        $titipan = $terlambat['titipan']->map(fn ($row) => $this->susun(
            'Barang titipan belum kembali',
            $row->nomor . ' · ' . ($row->supplier->nama ?? '-'),
            $row->estimasi_kembali,
            route('pengeluaran-barang.index'),
            (float) $row->nilai_taksiran,
        ));

        return $this->urutkan($keluar->merge($titipan)->all())->take($limit)->values();
    }

    public function subkontrakBelumKembali(int $limit = 20): Collection
    {
        $baris = $this->subkontrak->terlambat()->map(fn ($row) => $this->susun(
            'Barang subkontrak belum kembali',
            $row->nomor . ' · ' . ($row->supplier->nama ?? '-'),
            $row->estimasi_kembali,
            route('subkontrak.index'),
        ));

        return $this->urutkan($baris->all())->take($limit)->values();
    }

    public function crossDockBasi(?array $warehouseIds = null, int $limit = 20): Collection
    {
        $rows = CrossDock::with(['bahan', 'gudang'])
            ->where('status', CrossDock::DIRESERVASI)
            ->when($warehouseIds !== null, fn ($query) => $query->whereIn('gudang_id', $warehouseIds))
            ->whereDate('tanggal', '<=', today()->subDays(self::AMBANG_CROSS_DOCK_BASI_HARI))
            ->orderBy('tanggal')
            ->limit($limit)->get();

        return $this->urutkan($rows->map(fn ($tanda) => $this->susun(
            'Cross dock menahan stok',
            $tanda->nomor . ' · ' . ($tanda->bahan->nama ?? '-') . ' · ' . ($tanda->gudang->nama ?? '-'),
            $tanda->tanggal,
            route('cross-dock.index'),
            (float) $tanda->jumlah,
        ))->all());
    }

    public function perintahKerjaMandek(int $limit = 20): Collection
    {
        $batas = today()->subDays(self::AMBANG_PERINTAH_KERJA_MANDEK_HARI);

        $biayaTerakhir = DB::table('wms_data_pesanan_biaya')
            ->selectRaw('data_pesanan_id, MAX(tanggal) as tanggal_terakhir')
            ->groupBy('data_pesanan_id')
            ->pluck('tanggal_terakhir', 'data_pesanan_id');

        $rows = DataPesanan::with(['bahanHasil', 'gudang'])
            ->whereIn('status', [DataPesanan::DRAFT, DataPesanan::DIRILIS])
            ->get()
            ->map(fn (DataPesanan $pesanan) => [
                'pesanan' => $pesanan,
                'sejak' => $biayaTerakhir[$pesanan->id] ?? $pesanan->tanggal,
            ])
            ->filter(fn ($row) => $row['sejak'] && Carbon::parse($row['sejak'])->lte($batas))
            ->sortBy('sejak')
            ->take($limit);

        return $this->urutkan($rows->map(fn ($row) => $this->susun(
            'Perintah kerja mandek',
            $row['pesanan']->nomor . ' · ' . ($row['pesanan']->bahanHasil->nama ?? '-') . ' · ' . ($row['pesanan']->gudang->nama ?? '-'),
            $row['sejak'],
            route('data-pesanan.index'),
            (float) $row['pesanan']->jumlah_rencana,
        ))->all());
    }
}
