<?php

namespace App\Services;

use App\Models\AccountingSetting;
use App\Models\PembayaranFaktur;
use App\Models\Aset;
use App\Models\PenyusutanAset;
use App\Models\PelepasanAset;
use App\Models\Jurnal;
use App\Models\BaganAkun;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AsetAccountingService
{
    public function __construct(private AccountingPeriodService $periods, private DocumentNumberService $numbers) {}

    public function postAcquisition(Aset $asset): Jurnal
    {
        $this->periods->assertOpen($asset->acquisition_date, 'Perolehan aset');
        $asset->loadMissing('category');
        $this->assertCategoryMapping($asset);
        $this->assertAcquisitionAccount($asset);
        $cost = (float) $asset->acquisition_cost;
        $opening = (float) $asset->opening_accumulated_depreciation;
        if ($cost <= 0 || $opening < 0 || $opening > $cost) {
            throw new RuntimeException('Nilai perolehan atau akumulasi penyusutan awal tidak valid.');
        }

        $lines = [[
            'coa_id' => $asset->category->akun_aset_id,
            'debit' => $cost,
            'kredit' => 0,
            'keterangan' => "Perolehan {$asset->nomor_aset}"
        ]];
        if ($opening > 0) {
            $lines[] = [
                'coa_id' => $asset->category->accumulated_depreciation_coa_id,
                'debit' => 0,
                'kredit' => $opening,
                'keterangan' => 'Akumulasi penyusutan sebelum sistem'
            ];
        }
        $lines[] = [
            'coa_id' => $asset->acquisition_credit_coa_id,
            'debit' => 0,
            'kredit' => $cost - $opening,
            'keterangan' => "Sumber perolehan {$asset->acquisition_type}"
        ];

        return $this->post(
            "AST-{$asset->nomor_aset}",
            $asset->acquisition_date,
            'ASSET_ACQUISITION',
            $asset->id,
            "Perolehan aset {$asset->name}",
            $lines
        );
    }

    public function depreciate(Aset $asset, array $data, ?int $userId = null): PenyusutanAset
    {
        $this->periods->assertOpen($data['posting_date'], 'Penyusutan aset');
        return DB::transaction(function () use ($asset, $data, $userId) {
            $actorId = $userId ?? Auth::id();
            $asset = Aset::with('category')->lockForUpdate()->findOrFail($asset->id);
            if ($asset->status !== Aset::ACTIVE) throw new RuntimeException('Hanya aset aktif yang dapat disusutkan.');
            if ($asset->depreciations()->where('period_label', $data['period_label'])->exists()) {
                throw new RuntimeException("Aset {$asset->nomor_aset} sudah disusutkan untuk periode {$data['period_label']}.");
            }
            $this->assertCategoryMapping($asset);
            $amount = round((float) ($data['amount'] ?? $asset->suggestedMonthlyDepreciation()), 2);
            $maximum = round((float) $asset->book_value - (float) $asset->residual_value, 2);
            if ($amount <= 0) {
                throw new RuntimeException('Nominal penyusutan tidak dapat dihitung otomatis (umur ekonomis belum diisi); masukkan nominal secara manual.');
            }
            if ($amount > $maximum) {
                throw new RuntimeException("Penyusutan maksimal adalah {$maximum} agar tidak melewati nilai residu.");
            }
            $amountFiskal = $asset->suggestedMonthlyFiscalDepreciation();
            $depreciation = $asset->depreciations()->create([
                'posting_date' => $data['posting_date'],
                'period_label' => $data['period_label'],
                'suggested_amount' => $asset->suggestedMonthlyDepreciation(),
                'amount' => $amount,
                'amount_fiskal' => $amountFiskal,
                'book_value_before' => $asset->book_value,
                'book_value_after' => $maximum + $asset->residual_value - $amount,
                'reason' => $data['reason'] ?? null,
                'posted_by' => $actorId,
            ]);
            $journal = $this->post(
                "DEP-{$asset->nomor_aset}-{$depreciation->id}",
                $data['posting_date'],
                'ASSET_DEPRECIATION',
                $depreciation->id,
                "Penyusutan {$asset->name}",
                [
                    ['coa_id' => $asset->category->depreciation_expense_coa_id, 'debit' => $amount, 'kredit' => 0, 'keterangan' => 'Beban penyusutan'],
                    ['coa_id' => $asset->category->accumulated_depreciation_coa_id, 'debit' => 0, 'kredit' => $amount, 'keterangan' => 'Akumulasi penyusutan'],
                ],
                $actorId
            );
            $depreciation->update(['journal_id' => $journal->id]);
            $asset->update([
                'accumulated_depreciation' => (float) $asset->accumulated_depreciation + $amount,
                'book_value' => (float) $asset->book_value - $amount,
                'akumulasi_penyusutan_fiskal' => (float) $asset->akumulasi_penyusutan_fiskal + $amountFiskal,
                'last_depreciation_date' => $data['posting_date'],
            ]);
            return $depreciation->fresh('journal');
        });
    }

    public function runAutomaticDepreciation(string $postingDate, string $periodLabel): array
    {
        $posted = [];
        $skipped = [];
        $failed = [];
        $assets = Aset::where('status', Aset::ACTIVE)
            ->whereIn('depreciation_method', [Aset::STRAIGHT_LINE, Aset::DECLINING_BALANCE])->get();
        foreach ($assets as $asset) {
            if ($asset->depreciations()->where('period_label', $periodLabel)->exists()) {
                $skipped[] = ['nomor_aset' => $asset->nomor_aset, 'reason' => 'Sudah disusutkan pada periode ini.'];
                continue;
            }
            $maximum = round((float) $asset->book_value - (float) $asset->residual_value, 2);
            $suggested = $asset->suggestedMonthlyDepreciation();
            if ($suggested <= 0 || $maximum <= 0.01) {
                $skipped[] = ['nomor_aset' => $asset->nomor_aset, 'reason' => 'Nilai buku sudah mencapai residu atau umur ekonomis belum diisi.'];
                continue;
            }
            $amount = min($suggested, $maximum);
            try {
                $this->depreciate($asset, [
                    'posting_date' => $postingDate,
                    'period_label' => $periodLabel,
                    'amount' => $amount,
                    'reason' => $asset->depreciation_method === Aset::DECLINING_BALANCE
                        ? 'Penyusutan otomatis saldo menurun'
                        : 'Penyusutan otomatis garis lurus',
                ]);
                $posted[] = ['nomor_aset' => $asset->nomor_aset, 'amount' => $amount];
            } catch (RuntimeException $e) {
                $failed[] = ['nomor_aset' => $asset->nomor_aset, 'reason' => $e->getMessage()];
            }
        }
        return ['posted' => $posted, 'skipped' => $skipped, 'failed' => $failed];
    }

    public function dispose(Aset $asset, array $data, ?int $userId = null): PelepasanAset
    {
        $this->periods->assertOpen($data['disposal_date'], 'Pelepasan aset');
        return DB::transaction(function () use ($asset, $data, $userId) {
            $actorId = $userId ?? Auth::id();
            $asset = Aset::with('category')->lockForUpdate()->findOrFail($asset->id);
            if ($asset->status !== Aset::ACTIVE) throw new RuntimeException('Aset sudah tidak aktif.');
            $this->assertCategoryMapping($asset);
            $tradeIn = $data['disposal_type'] === PelepasanAset::TRADE_IN;
            $proceeds = in_array($data['disposal_type'], ['SALE', PelepasanAset::TRADE_IN], true)
                ? round((float) $data['proceeds'], 2) : 0;

            if ($data['disposal_type'] === 'SALE' && empty($data['cash_bank_coa_id'])) {
                throw new RuntimeException('Akun kas/bank wajib dipilih untuk penjualan aset.');
            }
            if ($data['disposal_type'] === 'SALE') {
                BaganAkun::assertUsable($data['cash_bank_coa_id'], [['ASET', 'DEBIT']], 'kas/bank penjualan aset', true);
            }
            if ($tradeIn && empty($data['supplier_id'])) {
                throw new RuntimeException('Supplier penerima tukar tambah wajib dipilih.');
            }

            $ppnKeluaran = $tradeIn ? round((float) ($data['ppn_keluaran'] ?? 0), 2) : 0;
            $book = (float) $asset->book_value;
            $gain = max($proceeds - $book, 0);
            $loss = max($book - $proceeds, 0);
            $disposal = PelepasanAset::create([
                'aset_id' => $asset->id,
                'disposal_date' => $data['disposal_date'],
                'disposal_type' => $data['disposal_type'],
                'proceeds' => $proceeds,
                'supplier_id' => $tradeIn ? $data['supplier_id'] : null,
                'pesanan_pembelian_id' => $tradeIn ? ($data['pesanan_pembelian_id'] ?? null) : null,
                'dpp_ppn_keluaran' => $tradeIn ? $proceeds : 0,
                'ppn_keluaran' => $ppnKeluaran,
                'cash_bank_coa_id' => $data['cash_bank_coa_id'] ?? null,
                'book_value_at_disposal' => $book,
                'gain_amount' => $gain,
                'loss_amount' => $loss,
                'reason' => $data['reason'],
                'disposed_by' => $actorId,
            ]);
            $lines = [];
            if ($proceeds > 0 && !$tradeIn) {
                $lines[] = ['coa_id' => $data['cash_bank_coa_id'], 'debit' => $proceeds, 'kredit' => 0, 'keterangan' => 'Hasil penjualan aset'];
            }
            if ($tradeIn) {
                $lines[] = [
                    'coa_id' => AccountingSetting::accountId(AccountingSetting::UANG_MUKA_SUPPLIER),
                    'debit' => round($proceeds + $ppnKeluaran, 2), 'kredit' => 0,
                    'keterangan' => "Nilai tukar tambah jadi uang muka supplier",
                ];
                if ($ppnKeluaran > 0) {
                    $lines[] = [
                        'coa_id' => AccountingSetting::accountId(AccountingSetting::PPN_KELUARAN),
                        'debit' => 0, 'kredit' => $ppnKeluaran,
                        'keterangan' => 'PPN Keluaran atas penyerahan tukar tambah',
                    ];
                }
            }
            if ((float) $asset->accumulated_depreciation > 0) $lines[] = [
                'coa_id' => $asset->category->accumulated_depreciation_coa_id,
                'debit' => (float) $asset->accumulated_depreciation,
                'kredit' => 0,
                'keterangan' => 'Hapus akumulasi penyusutan'
            ];
            if ($loss > 0) $lines[] = ['coa_id' => $asset->category->disposal_loss_coa_id, 'debit' => $loss, 'kredit' => 0, 'keterangan' => 'Rugi pelepasan aset'];
            $lines[] = ['coa_id' => $asset->category->akun_aset_id, 'debit' => 0, 'kredit' => (float) $asset->acquisition_cost, 'keterangan' => 'Hapus harga perolehan aset'];
            if ($gain > 0) $lines[] = ['coa_id' => $asset->category->disposal_gain_coa_id, 'debit' => 0, 'kredit' => $gain, 'keterangan' => 'Keuntungan pelepasan aset'];
            $journal = $this->post(
                "DSP-{$asset->nomor_aset}",
                $data['disposal_date'],
                'ASSET_DISPOSAL',
                $disposal->id,
                "Pelepasan aset {$asset->name}",
                $lines
            );
            $disposal->update(['journal_id' => $journal->id]);

            if ($tradeIn) {
                $disposal->update(['advance_payment_id' => $this->uangMukaTukarTambah($disposal, $proceeds + $ppnKeluaran, $actorId)]);
            }

            $asset->update(['status' => match ($data['disposal_type']) {
                'SALE' => Aset::SOLD,
                PelepasanAset::TRADE_IN => Aset::TRADED_IN,
                default => Aset::DISPOSED,
            }]);

            return $disposal->fresh('journal');
        });
    }

    private function uangMukaTukarTambah(PelepasanAset $disposal, float $nilai, ?int $userId): ?int
    {
        if ($nilai <= 0) {
            return null;
        }

        return PembayaranFaktur::create([
            'payment_number' => $this->numbers->financial('PY', $disposal->disposal_date),
            'invoice_lpb_id' => null,
            'supplier_id' => $disposal->supplier_id,
            'tanggal_pembayaran' => $disposal->disposal_date,
            'metode_pembayaran' => 'Uang Muka dari Tukar Tambah Aset',
            'coa_kas_bank_id' => null,
            'jumlah_pembayaran' => 0,
            'selisih_bayar' => $nilai,
            'jenis_selisih' => 'UANG_MUKA_SUPPLIER',
            'coa_selisih_id' => AccountingSetting::accountId(AccountingSetting::UANG_MUKA_SUPPLIER),
            'kelebihan_pembayaran' => $nilai,
            'total_transaksi_pengurang_hutang' => 0,
            'keterangan' => "Uang muka supplier dari tukar tambah aset {$disposal->asset->nomor_aset}",
            'finance_user_id' => $userId,
            'status' => PembayaranFaktur::POSTED,
        ])->id;
    }

    private function post(string $number, $date, string $source, int $referenceId, string $description, array $lines, ?int $userId = null): Jurnal
    {
        $userId ??= Auth::id();
        $lines = collect($lines)->filter(fn($line) => $line['debit'] > 0 || $line['kredit'] > 0)->values();
        $debit = round($lines->sum('debit'), 2);
        $credit = round($lines->sum('kredit'), 2);
        if ($debit <= 0 || abs($debit - $credit) > .01) throw new RuntimeException("Jurnal {$number} tidak seimbang.");
        $existing = Jurnal::where('sumber_transaksi', $source)->where('reff_id', $referenceId)->first();
        $journal = Jurnal::updateOrCreate([
            'sumber_transaksi' => $source,
            'reff_id' => $referenceId,
        ], [
            'no_jurnal' => $existing?->no_jurnal ?? $this->numbers->financial('JR', $date),
            'tanggal' => $date,
            'keterangan' => $description,
            'status' => 'POSTED',
            'created_by' => $userId,
            'posted_by' => $userId,
            'posted_at' => now(),
            'total_debit' => $debit,
            'total_kredit' => $credit,
        ]);
        $journal->details()->delete();
        $journal->details()->createMany($lines->all());
        return $journal;
    }

    private function assertCategoryMapping(Aset $asset): void
    {
        if (!$asset->category) {
            throw new RuntimeException('Kategori aset tidak tersedia.');
        }
        BaganAkun::assertUsable($asset->category->akun_aset_id, [['ASET', 'DEBIT']], 'harga perolehan aset');
        BaganAkun::assertUsable($asset->category->accumulated_depreciation_coa_id, [['ASET', 'KREDIT']], 'akumulasi penyusutan');
        BaganAkun::assertUsable($asset->category->depreciation_expense_coa_id, [['BEBAN', 'DEBIT']], 'beban penyusutan');
        BaganAkun::assertUsable($asset->category->disposal_gain_coa_id, [['PENDAPATAN', 'KREDIT']], 'keuntungan pelepasan aset');
        BaganAkun::assertUsable($asset->category->disposal_loss_coa_id, [['BEBAN', 'DEBIT']], 'kerugian pelepasan aset');
    }

    private function assertAcquisitionAccount(Aset $asset): void
    {
        [$allowed, $mustBeCash] = match ($asset->acquisition_type) {
            'CASH' => [[['ASET', 'DEBIT']], true],
            'CREDIT' => [[['LIABILITAS', 'KREDIT']], null],
            'GRANT', 'OPENING_BALANCE' => [[['EKUITAS', 'KREDIT']], null],
            'CORRECTION' => [[['EKUITAS', 'KREDIT'], ['PENDAPATAN', 'KREDIT']], null],
            default => throw new RuntimeException('Jenis perolehan aset tidak dikenali.'),
        };
        BaganAkun::assertUsable($asset->acquisition_credit_coa_id, $allowed, 'lawan perolehan aset', $mustBeCash);
    }
}
