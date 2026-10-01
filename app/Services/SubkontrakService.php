<?php

namespace App\Services;

use App\Models\DetailTransferGudang;
use App\Models\Gudang;
use App\Models\PengirimanSubkontrak;
use App\Models\PengirimanSubkontrakDetail;
use App\Models\TransferGudang;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubkontrakService
{
    public const KODE_GUDANG = 'GDG-SUBKONTRAK';

    public function __construct(
        private TransferGudangService $transfer,
        private DocumentNumberService $numbers,
    ) {}

    public function gudangSubkontrak(): Gudang
    {
        $gudang = Gudang::where('kode', self::KODE_GUDANG)->where('aktif', true)->first();

        if (!$gudang) {
            throw new RuntimeException('Gudang Subkontrak belum tersedia atau sudah dinonaktifkan.');
        }

        return $gudang;
    }

    public function kirim(array $data, User $user): PengirimanSubkontrak
    {
        return DB::transaction(function () use ($data, $user) {
            $asal = Gudang::findOrFail($data['gudang_asal_id']);
            $tujuan = $this->gudangSubkontrak();

            if ((int) $asal->id === (int) $tujuan->id) {
                throw new RuntimeException('Gudang asal tidak boleh Gudang Subkontrak itu sendiri.');
            }

            if (!$user->canAccessGudang((int) $asal->id, 'transfer')) {
                throw new RuntimeException('Anda tidak punya akses transfer pada gudang asal ini.');
            }

            $baris = collect($data['details'])
                ->map(fn ($row) => ['bahan_id' => (int) $row['bahan_id'], 'jumlah' => round((float) $row['jumlah'], 6)])
                ->filter(fn ($row) => $row['jumlah'] > 0)
                ->values();

            if ($baris->isEmpty()) {
                throw new RuntimeException('Pengiriman subkontrak harus memuat minimal satu baris bahan.');
            }

            if ($baris->pluck('bahan_id')->duplicates()->isNotEmpty()) {
                throw new RuntimeException('Satu bahan hanya boleh muncul sekali dalam satu pengiriman subkontrak.');
            }

            $pengiriman = PengirimanSubkontrak::create([
                'nomor' => $this->numbers->internal('SBK', 'OUT'),
                'tanggal' => $data['tanggal'],
                'supplier_id' => $data['supplier_id'],
                'gudang_asal_id' => $asal->id,
                'gudang_subkontrak_id' => $tujuan->id,
                'estimasi_kembali' => $data['estimasi_kembali'] ?? null,
                'status' => PengirimanSubkontrak::DIKIRIM,
                'keperluan' => $data['keperluan'] ?? null,
                'keterangan' => $data['keterangan'] ?? null,
                'dibuat_oleh' => $user->id,
            ]);

            foreach ($baris as $row) {
                PengirimanSubkontrakDetail::create([
                    'pengiriman_subkontrak_id' => $pengiriman->id,
                    'bahan_id' => $row['bahan_id'],
                    'jumlah' => $row['jumlah'],
                ]);
            }

            $transfer = $this->jalankanTransfer(
                $asal,
                $tujuan,
                $baris,
                $data['tanggal'],
                $user,
                "Pengiriman subkontrak {$pengiriman->nomor}"
            );

            $pengiriman->update(['transfer_keluar_id' => $transfer->id]);

            return $pengiriman->fresh(['details.bahan', 'supplier']);
        });
    }

    public function terima(PengirimanSubkontrak $pengiriman, array $diterima, $tanggal, User $user): PengirimanSubkontrak
    {
        return DB::transaction(function () use ($pengiriman, $diterima, $tanggal, $user) {
            $pengiriman = PengirimanSubkontrak::with('details')->lockForUpdate()->findOrFail($pengiriman->id);

            if (!$pengiriman->masihDiVendor()) {
                throw new RuntimeException('Pengiriman subkontrak ini sudah ditutup.');
            }

            $asal = $pengiriman->gudangSubkontrak;
            $tujuan = $pengiriman->gudangAsal;

            if (!$user->canAccessGudang((int) $tujuan->id, 'transfer')) {
                throw new RuntimeException('Anda tidak punya akses transfer pada gudang penerima.');
            }

            $baris = collect();

            foreach ($pengiriman->details as $detail) {
                $jumlah = round((float) ($diterima[$detail->id] ?? 0), 6);

                if ($jumlah <= 0) {
                    continue;
                }

                if ($jumlah > $detail->sisaDiVendor() + 0.000001) {
                    throw new RuntimeException("Jumlah kembali {$detail->bahan?->nama} melebihi yang masih ada di vendor.");
                }

                $baris->push(['bahan_id' => (int) $detail->bahan_id, 'jumlah' => $jumlah, 'detail' => $detail]);
            }

            if ($baris->isEmpty()) {
                throw new RuntimeException('Tidak ada barang yang ditandai kembali.');
            }

            $this->jalankanTransfer(
                $asal,
                $tujuan,
                $baris->map(fn ($row) => ['bahan_id' => $row['bahan_id'], 'jumlah' => $row['jumlah']]),
                $tanggal,
                $user,
                "Pengembalian subkontrak {$pengiriman->nomor}"
            );

            foreach ($baris as $row) {
                $row['detail']->update([
                    'jumlah_kembali' => round((float) $row['detail']->jumlah_kembali + $row['jumlah'], 6),
                ]);
            }

            $pengiriman = $pengiriman->fresh('details');
            $masihDiVendor = $pengiriman->details->sum(fn (PengirimanSubkontrakDetail $d) => $d->sisaDiVendor());

            $pengiriman->update([
                'status' => $masihDiVendor > 0.000001
                    ? PengirimanSubkontrak::SEBAGIAN_KEMBALI
                    : PengirimanSubkontrak::SELESAI,
            ]);

            return $pengiriman->fresh(['details.bahan', 'supplier']);
        });
    }

    public function terlambat(): Collection
    {
        return PengirimanSubkontrak::with('supplier')
            ->whereIn('status', [PengirimanSubkontrak::DIKIRIM, PengirimanSubkontrak::SEBAGIAN_KEMBALI])
            ->whereNotNull('estimasi_kembali')
            ->whereDate('estimasi_kembali', '<', today())
            ->orderBy('estimasi_kembali')
            ->get();
    }

    private function jalankanTransfer(Gudang $asal, Gudang $tujuan, Collection $baris, $tanggal, User $user, string $keterangan): TransferGudang
    {
        $transfer = TransferGudang::create([
            'nomor_transfer' => $this->numbers->internal('TRF', 'SBK'),
            'tanggal' => $tanggal,
            'gudang_asal_id' => $asal->id,
            'gudang_tujuan_id' => $tujuan->id,
            'status' => TransferGudang::DIAJUKAN,
            'keterangan' => $keterangan,
            'dibuat_oleh' => $user->id,
            'diajukan_oleh' => $user->id,
            'diajukan_pada' => now(),
        ]);

        foreach ($baris as $row) {
            DetailTransferGudang::create([
                'transfer_gudang_id' => $transfer->id,
                'bahan_id' => $row['bahan_id'],
                'jumlah' => $row['jumlah'],
            ]);
        }

        $this->transfer->konfirmasi($transfer);
        $this->transfer->terima($transfer->fresh(), [], $keterangan);

        return $transfer->fresh();
    }
}
