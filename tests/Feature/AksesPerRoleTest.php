<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AksesPerRoleTest extends TestCase
{
    use DatabaseTransactions;

    public static function kontrakAkses(): array
    {
        return [
            'purchasing' => [User::ROLE_PURCHASING, [
                'pembelian.index', 'request.index', 'supplier.index',
                'pelanggan.index', 'pesanan-penjualan.index', 'surat-jalan.index',
                'data-pesanan.index', 'lacak-pembelian.index', 'supplier-scorecard.index',
                'bom.index', 'routing-produksi.index', 'varians-pemakaian.index',
                'kinerja-sales.index',
            ], [
                'bagan-akun.index', 'period-lock.index',
                'piutang-aging.index', 'revaluasi-kurs.index', 'cross-dock.index',
            ]],

            'finance' => [User::ROLE_FINANCE, [
                'faktur-pembelian.index', 'faktur-penjualan.index', 'piutang-aging.index',
            ], [
                'bagan-akun.index', 'data-pesanan.index',
                'bom.index', 'revaluasi-kurs.index', 'cross-dock.index', 'kinerja-sales.index',
            ]],

            'warehouse' => [User::ROLE_WAREHOUSE, [
                'penerimaan-barang.index', 'pemakaian-barang.index', 'transfer-gudangs.index',
                'stock-opname.index', 'antrean-kerja.index',
                'surat-jalan.index', 'pesanan-penjualan.index',
                'cross-dock.index', 'bom.index', 'varians-pemakaian.index',
            ], [
                'bagan-akun.index', 'faktur-penjualan.index',
                'kinerja-sales.index', 'piutang-aging.index', 'revaluasi-kurs.index',
            ]],

            'accounting' => [User::ROLE_ACCOUNTING, [
                'bagan-akun.index', 'jurnal.index', 'period-lock.index', 'tax-rate.index',
                'financial-statements.neraca-saldo', 'financial-statements.calk',
                'reconciliation.index', 'rekonsiliasi-gudangs.index',
                'permintaan-persetujuan.index', 'faktur-penjualan.index', 'aset.index',
                'revaluasi-kurs.index', 'kinerja-sales.index', 'piutang-aging.index',
                'bom.index', 'varians-pemakaian.index',
            ], [
                'cross-dock.index',
            ]],

            'accounting_manager' => [User::ROLE_ACCOUNTING_MANAGER, [
                'jurnal.index', 'permintaan-persetujuan.index',
                'faktur-pembelian.index', 'executive-dashboard.index',
                'kinerja-sales.index', 'piutang-aging.index',
            ], [
                'bom.index', 'revaluasi-kurs.index', 'cross-dock.index',
            ]],

            'produksi' => [User::ROLE_PRODUCTION, [
                'pemakaian-barang.index', 'transfer-gudangs.index', 'data-pesanan.index',
                'bom.index', 'routing-produksi.index', 'varians-pemakaian.index',
                'cross-dock.index',
            ], [
                'bagan-akun.index', 'faktur-penjualan.index', 'pelanggan.index',
                'kinerja-sales.index', 'piutang-aging.index', 'revaluasi-kurs.index',
            ]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kontrakAkses')]
    public function test_each_role_can_open_the_pages_it_owns(int $tipe, array $wajibBisa, array $wajibDitolak): void
    {
        $user = User::factory()->create(['type' => $tipe]);

        $gagal = [];

        foreach ($wajibBisa as $nama) {
            $status = $this->actingAs($user)->get(route($nama))->getStatusCode();

            if ($status !== 200) {
                $gagal[] = "{$nama} => HTTP {$status} (seharusnya bisa dibuka)";
            }
        }

        foreach ($wajibDitolak as $nama) {
            $status = $this->actingAs($user)->get(route($nama))->getStatusCode();

            if ($status < 400) {
                $gagal[] = "{$nama} => HTTP {$status} (seharusnya ditolak)";
            }
        }

        $this->assertSame([], $gagal, "Kontrak akses role dilanggar:\n" . implode("\n", $gagal));
    }
}
