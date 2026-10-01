<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotifikasiService;
use App\Services\PengingatService;
use Illuminate\Console\Command;

class KirimPengingatHarian extends Command
{
    protected $signature = 'wms:pengingat-harian';
    protected $description = 'Kirim notifikasi tenggat dan antrean persetujuan ke peran yang berwenang';

    public function __construct(private PengingatService $pengingat, private NotifikasiService $notifikasi)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $kiriman = [
            'Invoice jatuh tempo' => [
                [User::ROLE_FINANCE, User::ROLE_ACCOUNTING],
                $this->pengingat->invoiceJatuhTempo(20),
            ],
            'Lot mendekati kedaluwarsa' => [
                [User::ROLE_WAREHOUSE, User::ROLE_PRODUCTION],
                $this->pengingat->lotSegeraKedaluwarsa(null, 20),
            ],
            'Transfer menggantung' => [
                [User::ROLE_WAREHOUSE],
                $this->pengingat->transferTerlaluLamaMenggantung(),
            ],
            'Faktur menunggu persetujuan' => [
                [User::ROLE_ACCOUNTING_MANAGER],
                $this->pengingat->invoiceMenungguPersetujuan(),
            ],
            'Request menunggu persetujuan' => [
                [User::ROLE_PURCHASING, User::ROLE_ACCOUNTING],
                $this->pengingat->requestMenungguPersetujuan(),
            ],
            'Piutang jatuh tempo' => [
                [User::ROLE_FINANCE, User::ROLE_ACCOUNTING],
                $this->pengingat->piutangJatuhTempo(20),
            ],
            'Barang keluar belum kembali' => [
                [User::ROLE_WAREHOUSE, User::ROLE_PURCHASING],
                $this->pengingat->barangKeluarBelumKembali(),
                $this->pengingat->subkontrakBelumKembali(),
            ],
            'Cross dock menahan stok' => [
                [User::ROLE_WAREHOUSE],
                $this->pengingat->crossDockBasi(null, 20),
            ],
            'Perintah kerja mandek' => [
                [User::ROLE_PRODUCTION, User::ROLE_PURCHASING],
                $this->pengingat->perintahKerjaMandek(),
            ],
        ];

        $total = 0;

        foreach ($kiriman as $judul => [$roles, $items]) {
            $penerima = $this->notifikasi->kirimPengingat($roles, $items);
            $total += $penerima;
            $this->line(sprintf('%-32s %3d item -> %d penerima', $judul, $items->count(), $penerima));
        }

        $this->info("Selesai. {$total} notifikasi diantrikan. Jalankan queue:work agar tersampaikan.");

        return self::SUCCESS;
    }
}
