<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class KualitasTampilanTest extends TestCase
{
    public function test_sidebar_submenus_use_the_shared_styled_component(): void
    {
        $sidebar = file_get_contents(resource_path('views/layouts/template/sidebar.blade.php'));

        $this->assertStringNotContainsString('class="sub-menu"', $sidebar);
        $this->assertStringContainsString('id="penjualan"', $sidebar);
        $this->assertStringContainsString('class="submenu', $sidebar);
    }

    use DatabaseTransactions;

    private const LEWATI = [
        'ignition.healthCheck', 'ignition.executeSolution', 'ignition.updateConfig',
        'password.reset', 'logout',
    ];

    private function halamanHtml(User $admin): array
    {
        $hasil = [];

        foreach (Route::getRoutes() as $route) {
            $nama = $route->getName();

            if (!$nama || !in_array('GET', $route->methods(), true)) {
                continue;
            }

            if (in_array($nama, self::LEWATI, true) || $route->parameterNames()) {
                continue;
            }

            if (str_contains($nama, '.pdf') || str_contains($nama, '.excel') || str_contains($nama, 'report')) {
                continue;
            }

            $response = $this->actingAs($admin)->get(route($nama));

            if ($response->getStatusCode() !== 200) {
                continue;
            }

            $isi = $response->getContent();

            if (!str_contains($isi, '<html') && !str_contains($isi, '<!DOCTYPE')) {
                continue;
            }

            $hasil[$nama] = $isi;
        }

        return $hasil;
    }

    public function test_every_blade_view_compiles_to_valid_php(): void
    {
        $compiler = app('blade.compiler');
        $rusak = [];

        $berkas = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views'))
        );

        $diperiksa = 0;

        foreach ($berkas as $file) {
            if (!$file->isFile() || !str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $diperiksa++;
            $php = $compiler->compileString(file_get_contents($file->getPathname()));
            $sementara = tempnam(sys_get_temp_dir(), 'blade') . '.php';
            file_put_contents($sementara, $php);

            exec('php -l ' . escapeshellarg($sementara) . ' 2>&1', $keluaran, $kode);
            @unlink($sementara);

            if ($kode !== 0) {
                $rusak[] = str_replace(resource_path('views') . DIRECTORY_SEPARATOR, '', $file->getPathname())
                    . ' => ' . implode(' ', array_slice($keluaran, 0, 2));
            }

            $keluaran = [];
        }

        $this->assertGreaterThan(80, $diperiksa);
        $this->assertSame([], $rusak, "View berikut tidak menghasilkan PHP yang valid:
" . implode("
", $rusak));
    }

    public function test_no_page_leaks_an_unrendered_blade_expression_or_raw_directive(): void
    {
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $masalah = [];

        foreach ($this->halamanHtml($admin) as $nama => $isi) {
            $badan = $isi;

            if (preg_match('/@(if|foreach|forelse|can|endif|endforeach|endcan|php|include|extends)\b/', $badan, $m)) {
                $masalah[] = "{$nama} => directive mentah '{$m[0]}' bocor ke HTML";
            }

            if (preg_match('/\{\{\s*\$[a-zA-Z_]/', $badan, $m)) {
                $masalah[] = "{$nama} => ekspresi Blade tidak terkompilasi: {$m[0]}";
            }
        }

        $this->assertSame([], $masalah, implode("\n", $masalah));
    }

    public function test_no_page_renders_a_placeholder_or_debug_leftover(): void
    {
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $masalah = [];

        $dilarang = ['Lorem ipsum', 'TODO:', 'FIXME', 'var_dump(', 'dd(', 'Undefined variable', 'htmlspecialchars('];

        foreach ($this->halamanHtml($admin) as $nama => $isi) {
            foreach ($dilarang as $kata) {
                if (str_contains($isi, $kata)) {
                    $masalah[] = "{$nama} => mengandung '{$kata}'";
                }
            }
        }

        $this->assertSame([], $masalah, implode("\n", $masalah));
    }

    public function test_every_listing_page_tells_the_user_when_it_is_empty(): void
    {
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $masalah = [];

        foreach ($this->halamanHtml($admin) as $nama => $isi) {
            if (!str_ends_with($nama, '.index')) {
                continue;
            }

            if (!str_contains($isi, '<table')) {
                continue;
            }

            $punyaBaris = preg_match('/<tbody[^>]*>\s*<tr/i', $isi) === 1;
            $punyaTemplate = str_contains($isi, 'x-for');
            $punyaKosong = preg_match('/(Belum ada|Tidak ada|belum ada|tidak ada|Data tidak|kosong)/u', $isi) === 1;

            if (!$punyaBaris && !$punyaTemplate && !$punyaKosong) {
                $masalah[] = "{$nama} => tabel kosong tanpa pesan keadaan kosong";
            }
        }

        $this->assertSame([], $masalah, implode("\n", $masalah));
    }

    public function test_no_page_hardcodes_colors_outside_the_theme(): void
    {
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $masalah = [];

        $terlarang = [
            'bg-white', 'bg-black', 'text-white', 'text-black',
            'bg-gray-', 'text-gray-', 'border-gray-',
            'bg-slate-', 'text-slate-', 'border-slate-',
            'bg-zinc-', 'text-zinc-',
        ];

        foreach ($this->halamanHtml($admin) as $nama => $isi) {
            foreach ($terlarang as $kelas) {
                if (preg_match('/class="[^"]*\b' . preg_quote($kelas, '/') . '/', $isi)) {
                    $masalah[] = "{$nama} => memakai kelas warna mati '{$kelas}'; pakai token daisyUI (base-100/base-content/primary-content)";
                }
            }

            if (preg_match('/style="[^"]*(?:color|background)[^"]*#[0-9a-fA-F]{3,6}/', $isi, $m)) {
                $masalah[] = "{$nama} => warna heksadesimal ditulis inline: {$m[0]}";
            }
        }

        $this->assertSame([], $masalah, implode("\n", array_unique($masalah)));
    }

    public function test_every_page_keeps_its_wide_tables_horizontally_scrollable(): void
    {
        $admin = User::factory()->create(['type' => User::ROLE_SUPER_ADMIN]);
        $masalah = [];

        foreach ($this->halamanHtml($admin) as $nama => $isi) {
            $jumlahTabel = substr_count($isi, '<table');

            if ($jumlahTabel === 0) {
                continue;
            }

            $jumlahPembungkus = substr_count($isi, 'overflow-x-auto')
                + substr_count($isi, 'overflow-auto')
                + substr_count($isi, 'overflow-x:auto');

            if ($jumlahPembungkus < $jumlahTabel) {
                $masalah[] = "{$nama} => {$jumlahTabel} tabel tapi hanya {$jumlahPembungkus} pembungkus scroll";
            }
        }

        $this->assertSame([], $masalah, implode("\n", $masalah));
    }
}
