<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\BaganAkun;
use App\Models\KategoriBahan;
use App\Models\PemakaianBarang;
use App\Models\PenerimaanBarang;
use App\Models\PermintaanPersetujuan;
use App\Models\ReturPembelian;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PersetujuanOperasiService
{
    public const REVERSE_LPB = 'REVERSE_LPB';
    public const REVERSE_NPK = 'REVERSE_NPK';
    public const REVERSE_RETUR = 'REVERSE_RETUR';
    public const COA_UBAH = 'COA_UBAH';
    public const COA_MAPPING = 'COA_MAPPING';

    public function __construct(
        private DocumentNumberService $numbers,
        private InventoryReversalService $reversals,
    ) {}

    public function ajukan(
        string $jenis,
        string $subJenis,
        string $ringkasan,
        array $payload,
        string $alasan,
        User $pemohon,
        ?Model $referensi = null,
    ): PermintaanPersetujuan {
        $this->assertSubJenisDikenal($jenis, $subJenis);

        if ($referensi) {
            $tertunda = PermintaanPersetujuan::pending()
                ->where('referensi_type', $referensi::class)
                ->where('referensi_id', $referensi->getKey())
                ->where('sub_jenis', $subJenis)
                ->exists();

            if ($tertunda) {
                throw new RuntimeException('Permintaan serupa untuk dokumen ini masih menunggu persetujuan.');
            }
        }

        return PermintaanPersetujuan::create([
            'nomor' => $this->numbers->internal('APR', 'PRS'),
            'jenis' => $jenis,
            'sub_jenis' => $subJenis,
            'referensi_type' => $referensi?->getMorphClass(),
            'referensi_id' => $referensi?->getKey(),
            'ringkasan' => $ringkasan,
            'payload' => $payload,
            'status' => PermintaanPersetujuan::PENDING,
            'alasan' => $alasan,
            'diminta_oleh' => $pemohon->id,
        ]);
    }

    public function setujui(PermintaanPersetujuan $permintaan, User $pemutus, ?string $catatan = null): PermintaanPersetujuan
    {
        $this->assertDapatDiputuskan($permintaan, $pemutus);

        return DB::transaction(function () use ($permintaan, $pemutus, $catatan) {
            $terkunci = PermintaanPersetujuan::lockForUpdate()->findOrFail($permintaan->id);

            if (!$terkunci->isPending()) {
                throw new RuntimeException('Permintaan ini sudah diputuskan sebelumnya.');
            }

            $this->jalankan($terkunci, $pemutus->id);

            $terkunci->update([
                'status' => PermintaanPersetujuan::APPROVED,
                'catatan_checker' => $catatan,
                'diputuskan_oleh' => $pemutus->id,
                'diputuskan_pada' => now(),
            ]);

            return $terkunci;
        });
    }

    public function tolak(PermintaanPersetujuan $permintaan, User $pemutus, ?string $catatan = null): PermintaanPersetujuan
    {
        $this->assertDapatDiputuskan($permintaan, $pemutus);

        if (!$permintaan->isPending()) {
            throw new RuntimeException('Permintaan ini sudah diputuskan sebelumnya.');
        }

        $permintaan->update([
            'status' => PermintaanPersetujuan::REJECTED,
            'catatan_checker' => $catatan,
            'diputuskan_oleh' => $pemutus->id,
            'diputuskan_pada' => now(),
        ]);

        return $permintaan;
    }

    private function assertDapatDiputuskan(PermintaanPersetujuan $permintaan, User $pemutus): void
    {
        if ((int) $permintaan->diminta_oleh === (int) $pemutus->id) {
            throw new RuntimeException('Pemohon tidak boleh menyetujui permintaannya sendiri.');
        }
    }

    private function assertSubJenisDikenal(string $jenis, string $subJenis): void
    {
        $dikenal = match ($jenis) {
            PermintaanPersetujuan::PEMBALIKAN_DOKUMEN => [self::REVERSE_LPB, self::REVERSE_NPK, self::REVERSE_RETUR],
            PermintaanPersetujuan::PERUBAHAN_COA => [self::COA_UBAH, self::COA_MAPPING],
            default => [],
        };

        if (!in_array($subJenis, $dikenal, true)) {
            throw new RuntimeException('Jenis permintaan persetujuan tidak dikenal.');
        }
    }

    private function jalankan(PermintaanPersetujuan $permintaan, int $userId): void
    {
        $payload = $permintaan->payload ?? [];

        match ($permintaan->sub_jenis) {
            self::REVERSE_LPB => $this->reversals->reverseLpb(
                PenerimaanBarang::findOrFail($permintaan->referensi_id),
                (string) $permintaan->alasan,
                $userId
            ),
            self::REVERSE_NPK => $this->reversals->reverseNpk(
                PemakaianBarang::findOrFail($permintaan->referensi_id),
                (string) $permintaan->alasan,
                $userId
            ),
            self::REVERSE_RETUR => $this->reversals->reverseReturPembelian(
                ReturPembelian::findOrFail($permintaan->referensi_id),
                (string) $permintaan->alasan,
                $userId
            ),
            self::COA_UBAH => $this->terapkanPerubahanCoa($permintaan, $payload),
            self::COA_MAPPING => $this->terapkanMapping($payload),
            default => throw new RuntimeException('Jenis permintaan persetujuan tidak dikenal.'),
        };
    }

    private function terapkanPerubahanCoa(PermintaanPersetujuan $permintaan, array $payload): void
    {
        $akun = BaganAkun::findOrFail($permintaan->referensi_id);
        $akun->update($payload);
    }

    private function terapkanMapping(array $payload): void
    {
        foreach ($payload['global'] ?? [] as $kunci => $coaId) {
            AccountingSetting::updateOrCreate(
                ['key' => $kunci],
                ['coa_id' => $coaId === null ? null : (int) $coaId]
            );
        }

        foreach ($payload['categories'] ?? [] as $kategoriId => $mapping) {
            KategoriBahan::whereKey($kategoriId)->update($mapping);
        }
    }
}
