<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use ReflectionMethod;
use Tests\TestCase;

class TanpaNPlusSatuTest extends TestCase
{
    use DatabaseTransactions;

    private const BATAS_POLA_BERULANG = 5;

    private const LEWATI = [
        'ignition.healthCheck',
        'ignition.executeSolution',
        'ignition.updateConfig',
        'password.reset',
        'logout',
    ];

    private function nilaiParameter(string $nama): ?string
    {
        $peta = [
            'aset' => ['wms_asets', 'id'],
            'bahan' => ['bahans', 'id'],
            'chart_of_account' => ['wms_bagan_akun', 'id'],
            'faktur' => ['wms_faktur_penjualan', 'id'],
            'faktur_pembelian' => ['wms_faktur_pembelian', 'id'],
            'gudang' => ['gudangs', 'id'],
            'id_lpb' => ['wms_penerimaan_barang', 'id_lpb'],
            'jurnal' => ['wms_jurnal', 'id'],
            'kategori_bahan' => ['kategori_bahans', 'id'],
            'kit' => ['wms_kit', 'id'],
            'lampiran' => ['wms_lampiran_dokumen', 'id'],
            'lpb' => ['wms_penerimaan_barang', 'id'],
            'no_po' => ['wms_pesanan_pembelian', 'no_po'],
            'npk' => ['wms_pemakaian_barang', 'id'],
            'pelanggan' => ['pelanggans', 'id'],
            'pemeriksaan_consider' => ['pemeriksaan_considers', 'id'],
            'pesanan' => ['wms_data_pesanan', 'id'],
            'request' => ['requests', 'id'],
            'requestdetail' => ['request_details', 'id'],
            'retur_pembelian' => ['wms_retur_pembelian', 'id'],
            'service_bap' => ['wms_penerimaan_barang', 'id'],
            'service_purchase' => ['wms_pesanan_pembelian', 'id'],
            'stock_opname' => ['wms_stock_opname', 'id'],
            'stok_gudang' => ['stok_gudangs', 'id'],
            'supplier' => ['suppliers', 'id'],
            'suratJalan' => ['wms_surat_jalan', 'id'],
            'tipe_pembebanan' => ['tipe_pembebanans', 'id'],
            'transfer_gudang' => ['transfer_gudangs', 'id'],
            'user' => ['users', 'id'],
        ];

        if ($nama === 'check') {
            return 'stock';
        }

        if (! isset($peta[$nama])) {
            return null;
        }

        [$tabel, $kolom] = $peta[$nama];
        $nilai = DB::table($tabel)->value($kolom);

        return $nilai === null ? null : (string) $nilai;
    }

    private function parameter(RoutingRoute $route): ?array
    {
        $parameter = [];

        foreach ($route->parameterNames() as $nama) {
            $nilai = $this->nilaiParameter($nama);

            if ($nilai === null) {
                return null;
            }

            $parameter[$nama] = $nilai;
        }

        return $parameter;
    }

    private function adalahYajra(RoutingRoute $route): bool
    {
        $action = $route->getActionName();

        if (! str_contains($action, '@')) {
            return false;
        }

        [$kelas, $metode] = explode('@', $action, 2);
        $reflection = new ReflectionMethod($kelas, $metode);
        $baris = file($reflection->getFileName());
        $sumber = implode('', array_slice(
            $baris,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));

        return str_contains($sumber, 'datatables()') || str_contains($sumber, 'DataTables::');
    }

    private function polaQuery(string $sql, array $bindings): string
    {
        $sql = strtolower(trim(preg_replace('/\s+/', ' ', $sql)));
        $sql = preg_replace("/'(?:''|[^'])*'/", '?', $sql);
        $sql = preg_replace('/\b\d+(?:\.\d+)?\b/', '?', $sql);

        return $sql.' | bindings:'.count($bindings);
    }

    public function test_semua_halaman_bebas_dari_n_plus_satu(): void
    {
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);
        $pelanggaranLazy = [];
        $pelanggaranQuery = [];
        $error = [];
        $pengukuran = [];
        $queryAktif = [];
        $ruteAktif = '';

        Model::preventLazyLoading(true);
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) use (&$pelanggaranLazy, &$ruteAktif) {
            $pelanggaranLazy[] = $ruteAktif.' => '.$model::class.'::'.$relation;
        });

        DB::listen(function ($query) use (&$queryAktif) {
            $queryAktif[] = $this->polaQuery($query->sql, $query->bindings);
        });

        try {
            foreach (Route::getRoutes() as $route) {
                $nama = $route->getName();

                if (! $nama || ! in_array('GET', $route->methods(), true) || in_array($nama, self::LEWATI, true)) {
                    continue;
                }

                $parameter = $this->parameter($route);

                if ($parameter === null) {
                    continue;
                }

                $permintaan = [['label' => $nama, 'ajax' => false]];

                if ($this->adalahYajra($route)) {
                    $permintaan[] = ['label' => $nama.' [Yajra]', 'ajax' => true];
                }

                foreach ($permintaan as $item) {
                    $ruteAktif = $item['label'];
                    $queryAktif = [];

                    try {
                        $request = $this->actingAs($admin);

                        if ($item['ajax']) {
                            $request = $request->withHeaders([
                                'Accept' => 'application/json',
                                'X-Requested-With' => 'XMLHttpRequest',
                            ]);
                        }

                        $response = $request->get(route($nama, $parameter).($item['ajax'] ? '?draw=1&start=0&length=100' : ''));

                        if ($response->getStatusCode() >= 500) {
                            $error[] = $ruteAktif.' => HTTP '.$response->getStatusCode();
                        }
                    } catch (\Throwable $exception) {
                        $error[] = $ruteAktif.' => '.$exception::class.': '.$exception->getMessage();
                    }

                    $frekuensi = array_count_values($queryAktif);
                    arsort($frekuensi);
                    $terbanyak = $frekuensi ? max($frekuensi) : 0;
                    $pengukuran[$ruteAktif] = [count($queryAktif), $terbanyak];

                    foreach ($frekuensi as $pola => $jumlah) {
                        if ($jumlah > self::BATAS_POLA_BERULANG) {
                            $pelanggaranQuery[] = $ruteAktif." => {$jumlah}x {$pola}";
                        }
                    }
                }
            }
        } finally {
            Model::preventLazyLoading(false);
        }

        uasort($pengukuran, fn (array $a, array $b) => $b[0] <=> $a[0]);
        $ringkasan = collect($pengukuran)
            ->map(fn (array $nilai, string $nama) => "{$nama}: {$nilai[0]} query, pola terbanyak {$nilai[1]}x")
            ->implode("\n");

        if (filter_var(getenv('NPLUS1_REPORT'), FILTER_VALIDATE_BOOL)) {
            fwrite(STDOUT, "\n{$ringkasan}\n");
        }

        $this->assertGreaterThan(150, count($pengukuran), 'Terlalu sedikit halaman yang diukur.');
        $this->assertSame([], $error, "Halaman berikut gagal diukur:\n".implode("\n", $error));
        $this->assertSame([], array_values(array_unique($pelanggaranLazy)), "Lazy loading relasi terdeteksi:\n".implode("\n", array_unique($pelanggaranLazy))."\n\nPengukuran:\n{$ringkasan}");
        $this->assertSame([], $pelanggaranQuery, 'Pola query identik melewati batas '.self::BATAS_POLA_BERULANG.":\n".implode("\n", $pelanggaranQuery)."\n\nPengukuran:\n{$ringkasan}");
    }
}
