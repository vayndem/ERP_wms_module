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

    private const PERAN_OPERASIONAL = [
        User::ROLE_WAREHOUSE,
        User::ROLE_PRODUCTION,
        User::ROLE_PURCHASING,
        User::ROLE_FINANCE,
        User::ROLE_ACCOUNTING,
        User::ROLE_ACCOUNTING_MANAGER,
    ];

    private function nilaiParameter(string $nama): ?string
    {
        $peta = [
            'aset' => ['wms_asets', 'id', null],
            'bahan' => ['bahans', 'id', null],
            'buktiPotong' => ['wms_bukti_potong', 'id', null],
            'chart_of_account' => ['wms_bagan_akun', 'id', ['wms_jurnal_detail', 'coa_id']],
            'faktur' => ['wms_faktur_penjualan', 'id', ['wms_faktur_penjualan_detail', 'faktur_penjualan_id']],
            'faktur_pembelian' => ['wms_faktur_pembelian', 'id', null],
            'gudang' => ['gudangs', 'id', ['stok_gudangs', 'gudang_id']],
            'id_lpb' => ['wms_penerimaan_barang', 'id_lpb', ['wms_penerimaan_barang_detail', 'id_lpb']],
            'jurnal' => ['wms_jurnal', 'id', ['wms_jurnal_detail', 'jurnal_id']],
            'kategori_bahan' => ['kategori_bahans', 'id', ['bahans', 'tipe_barang']],
            'kit' => ['wms_kit', 'id', null],
            'lampiran' => ['wms_lampiran_dokumen', 'id', null],
            'lpb' => ['wms_penerimaan_barang', 'id', ['wms_penerimaan_barang_detail', 'id_lpb']],
            'no_po' => ['wms_pesanan_pembelian', 'no_po', ['wms_pesanan_pembelian_detail', 'no_po']],
            'npk' => ['wms_pemakaian_barang', 'id', null],
            'pelanggan' => ['pelanggans', 'id', null],
            'pemeriksaan_consider' => ['pemeriksaan_considers', 'id', null],
            'pesanan' => ['wms_data_pesanan', 'id', null],
            'request' => ['requests', 'id', null],
            'requestdetail' => ['request_details', 'id', null],
            'retur_pembelian' => ['wms_retur_pembelian', 'id', null],
            'service_bap' => ['wms_penerimaan_barang', 'id', ['wms_penerimaan_barang_detail', 'id_lpb']],
            'service_purchase' => ['wms_pesanan_pembelian', 'id', null],
            'stock_opname' => ['wms_stock_opname', 'id', null],
            'stok_gudang' => ['stok_gudangs', 'id', null],
            'supplier' => ['suppliers', 'id', null],
            'suratJalan' => ['wms_surat_jalan', 'id', null],
            'tipe_pembebanan' => ['tipe_pembebanans', 'id', null],
            'transfer_gudang' => ['transfer_gudangs', 'id', null],
            'user' => ['users', 'id', null],
        ];

        if ($nama === 'check') {
            return 'stock';
        }

        if (! isset($peta[$nama])) {
            return null;
        }

        [$tabel, $kolom, $anak] = $peta[$nama];

        if ($anak !== null) {
            $nilai = DB::table($tabel)
                ->join($anak[0], $anak[0].'.'.$anak[1], '=', $tabel.'.'.$kolom)
                ->groupBy($tabel.'.'.$kolom)
                ->orderByRaw('COUNT(*) DESC')
                ->orderBy($tabel.'.'.$kolom)
                ->value($tabel.'.'.$kolom);
        }

        $nilai ??= DB::table($tabel)->value($kolom);

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

    private function batas(): int
    {
        return (int) (getenv('NPLUS1_AMBANG') ?: self::BATAS_POLA_BERULANG);
    }

    private function adalahEagerLoad(string $pola): bool
    {
        return (bool) preg_match('/^select \* from `\w+` where `\w+`\.`\w+` in \([?, ]+\)( and `\w+`\.`deleted_at` is null)? \| bindings:0$/', $pola);
    }

    private function sapu(User $pengguna, string $awalan = ''): array
    {
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
                    $ruteAktif = $awalan.$item['label'];
                    $queryAktif = [];

                    try {
                        $this->flushHeaders();
                        $request = $this->actingAs($pengguna);

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
                        if ($jumlah >= 2 && getenv('NPLUS1_DETAIL') && ! $this->adalahEagerLoad($pola)) {
                            file_put_contents(getenv('NPLUS1_DETAIL'), $ruteAktif.' | '.$jumlah.'x | '.substr($pola, 0, 200).PHP_EOL, FILE_APPEND);
                        }
                        if ($jumlah > $this->batas() && ! $this->adalahEagerLoad($pola)) {
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

        return compact('pelanggaranLazy', 'pelanggaranQuery', 'error', 'pengukuran', 'ringkasan');
    }

    private function nyatakan(array $hasil, int $minimalHalaman): void
    {
        $this->assertGreaterThan($minimalHalaman, count($hasil['pengukuran']), 'Terlalu sedikit halaman yang diukur.');
        $this->assertSame([], $hasil['error'], "Halaman berikut gagal diukur:\n".implode("\n", $hasil['error']));
        $this->assertSame([], array_values(array_unique($hasil['pelanggaranLazy'])), "Lazy loading relasi terdeteksi:\n".implode("\n", array_unique($hasil['pelanggaranLazy']))."\n\nPengukuran:\n{$hasil['ringkasan']}");
        $this->assertSame([], $hasil['pelanggaranQuery'], 'Pola query identik melewati batas '.self::BATAS_POLA_BERULANG.":\n".implode("\n", $hasil['pelanggaranQuery'])."\n\nPengukuran:\n{$hasil['ringkasan']}");
    }

    public function test_semua_halaman_bebas_dari_n_plus_satu(): void
    {
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN, 'is_active' => true]);

        $this->nyatakan($this->sapu($admin), 150);
    }

    public function test_semua_halaman_bebas_dari_n_plus_satu_untuk_peran_operasional(): void
    {
        $diukur = 0;
        $gabungan = ['pelanggaranLazy' => [], 'pelanggaranQuery' => [], 'error' => [], 'pengukuran' => [], 'ringkasan' => ''];

        foreach (self::PERAN_OPERASIONAL as $peran) {
            $pengguna = User::where('type', $peran)->where('is_active', true)->orderBy('id')->first();

            if (! $pengguna) {
                continue;
            }

            $hasil = $this->sapu($pengguna, User::ROLE_NAMES[$peran].': ');
            $diukur += count($hasil['pengukuran']);

            foreach (['pelanggaranLazy', 'pelanggaranQuery', 'error'] as $kunci) {
                $gabungan[$kunci] = array_merge($gabungan[$kunci], $hasil[$kunci]);
            }

            $gabungan['pengukuran'] += $hasil['pengukuran'];
            $gabungan['ringkasan'] .= $hasil['ringkasan']."\n";
        }

        $this->nyatakan($gabungan, 300);
    }
}
