<?php

namespace App\Console\Commands;

use App\Services\AsetAccountingService;
use Illuminate\Console\Command;
use Throwable;

class JalankanPenyusutanBulanan extends Command
{
    protected $signature = 'wms:penyusutan-bulanan {--periode= : Label periode YYYY-MM, default bulan lalu}';
    protected $description = 'Jalankan penyusutan otomatis untuk seluruh aset aktif pada satu periode';

    public function __construct(private AsetAccountingService $aset)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $periode = $this->option('periode') ?: now()->subMonthNoOverflow()->format('Y-m');
        $tanggalPosting = now()->subMonthNoOverflow()->endOfMonth()->toDateString();

        if ($this->option('periode')) {
            $tanggalPosting = $periode . '-01';
        }

        try {
            $hasil = $this->aset->runAutomaticDepreciation($tanggalPosting, $periode);
        } catch (Throwable $e) {
            $this->error('Penyusutan gagal: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Periode %s — diposting: %d, dilewati: %d, gagal: %d',
            $periode,
            count($hasil['posted'] ?? []),
            count($hasil['skipped'] ?? []),
            count($hasil['failed'] ?? []),
        ));

        foreach ($hasil['failed'] ?? [] as $gagal) {
            $this->warn('  gagal: ' . ($gagal['nomor_aset'] ?? '?') . ' — ' . ($gagal['reason'] ?? ''));
        }

        return ($hasil['failed'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }
}
