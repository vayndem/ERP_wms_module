<?php

namespace Tests\Feature;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class KonsistensiTemaTest extends TestCase
{
    private const KELUARGA = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

    private const AWALAN = 'bg|text|border|from|via|to|ring|divide|outline|decoration|shadow|accent|caret|placeholder|fill|stroke';

    private const VARIAN = '(?:hover:|focus:|focus-within:|active:|disabled:|dark:|group-hover:|peer-focus:|sm:|md:|lg:|xl:|2xl:)*';

    private const DIKECUALIKAN = [
        'resources/views/reports/',
        'resources/views/stock_opname/pdf.blade.php',
        'resources/views/vendor/',
    ];

    private const PALET_GRAFIK = 'resources/js/task-chart.js';

    private function pola(): array
    {
        $keluarga = self::KELUARGA;
        $awalan = self::AWALAN;
        $varian = self::VARIAN;

        return [
            'kelas palet Tailwind bernomor' => "/(?:^|[\\s\"'`:>])" . $varian . "(?:{$awalan})-(?:{$keluarga})-(?:50|100|200|300|400|500|600|700|800|900|950)\\b/",
            'kelas putih/hitam mati' => "/(?:^|[\\s\"'`:>])" . $varian . "(?:{$awalan})-(?:white|black)(?:\\/\\d+)?\\b/",
            'nilai warna arbitrer' => "/(?:{$awalan})-\\[(?:#[0-9a-fA-F]{3,8}|rgba?\\(|hsla?\\()/",
            'warna heksadesimal inline' => '/style\\s*=\\s*"[^"]*(?:color|background|border-color|fill|stroke)\\s*:[^"]*(?:#[0-9a-fA-F]{3,8}|rgba?\\(|hsla?\\()/',
            'helper theme() warna' => "/theme\\(\\s*['\"]colors\\./",
            'warna mentah di CSS' => '/(?:^|[\\s:])(?:color|background(?:-color)?|border(?:-[a-z]+)?-color|fill|stroke)\\s*:\\s*(?:#[0-9a-fA-F]{3,8}|rgba?\\(|hsla?\\()/',
        ];
    }

    private function berkasSumber(): array
    {
        $daftar = [];

        foreach ([['resources/views', '.blade.php'], ['resources/js', '.js'], ['resources/css', '.css']] as [$akar, $ext]) {
            if (!is_dir(base_path($akar))) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($akar))) as $berkas) {
                if (!$berkas->isFile() || !str_ends_with($berkas->getFilename(), $ext)) {
                    continue;
                }

                $rel = str_replace('\\', '/', $berkas->getPathname());
                $rel = str_replace(str_replace('\\', '/', base_path()) . '/', '', $rel);

                foreach (self::DIKECUALIKAN as $kecuali) {
                    if (str_contains($rel, $kecuali)) {
                        continue 2;
                    }
                }

                $daftar[$rel] = $berkas->getPathname();
            }
        }

        return $daftar;
    }

    public function test_no_source_file_hardcodes_a_colour_outside_the_theme(): void
    {
        $pelanggar = [];
        $berkas = $this->berkasSumber();

        foreach ($berkas as $rel => $path) {
            $adalahCss = str_ends_with($rel, '.css');

            foreach (file($path) as $nomor => $isi) {
                foreach ($this->pola() as $label => $pola) {
                    if ($label === 'warna mentah di CSS' && !$adalahCss) {
                        continue;
                    }

                    if (preg_match($pola, $isi, $cocok)) {
                        $pelanggar[] = "{$rel}:" . ($nomor + 1) . " [{$label}] " . trim($cocok[0]);
                    }
                }
            }
        }

        $this->assertGreaterThan(100, count($berkas), 'Pemindaian tema tidak menjangkau cukup berkas.');
        $this->assertSame(
            [],
            $pelanggar,
            "Warna harus memakai token daisyUI (base-100/base-200/base-300/base-content/primary-content/neutral-content), bukan nilai mati:\n"
                . implode("\n", $pelanggar)
        );
    }

    public function test_the_chart_palette_is_the_only_place_javascript_names_colours(): void
    {
        $isi = file_get_contents(base_path(self::PALET_GRAFIK));

        $this->assertMatchesRegularExpression(
            '/const PALETTE = \[/',
            $isi,
            'Palet seri grafik hilang; warna seri data memang boleh eksplisit karena canvas tidak mewarisi kelas CSS.'
        );

        $this->assertStringContainsString(
            'inventory:theme-changed',
            $isi,
            'Grafik wajib memasang ulang warnanya saat tema berganti, jika tidak sumbu dan grid tak terbaca di mode gelap.'
        );

        foreach (['--bc', '--n', '--nc', '--b1'] as $token) {
            $this->assertStringContainsString(
                $token,
                $isi,
                "Grafik harus membaca token daisyUI {$token} dari CSS, bukan menebak warna."
            );
        }

        $this->assertStringContainsString("document.querySelectorAll('[data-task-chart]')", $isi);
        $this->assertStringNotContainsString('this.$refs.canvas', $isi, 'Instance Chart.js tidak boleh masuk proxy reaktif Alpine karena memicu error resolver di browser.');
    }

    public function test_both_themes_exist_and_keep_their_elevation_ramp(): void
    {
        $config = file_get_contents(base_path('tailwind.config.js'));

        $this->assertStringContainsString("wms:", $config);
        $this->assertStringContainsString("'wms-dark'", $config);

        $ambil = function (string $tema, string $token) use ($config): string {
            $potongan = substr($config, strpos($config, $tema));
            preg_match("/'" . preg_quote($token, '/') . "':\\s*'(#[0-9a-fA-F]{6})'/", $potongan, $m);

            return $m[1] ?? '';
        };

        $luminansi = function (string $heks): float {
            $heks = ltrim($heks, '#');
            $kanal = [];

            foreach ([0, 2, 4] as $i) {
                $c = hexdec(substr($heks, $i, 2)) / 255;
                $kanal[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }

            return 0.2126 * $kanal[0] + 0.7152 * $kanal[1] + 0.0722 * $kanal[2];
        };

        $kontras = function (string $a, string $b) use ($luminansi): float {
            $la = $luminansi($a);
            $lb = $luminansi($b);

            return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
        };

        foreach (['wms:' => 'terang', "'wms-dark'" => 'gelap'] as $tema => $label) {
            $permukaan = $ambil($tema, 'base-100');
            $halaman = $ambil($tema, 'base-200');
            $teks = $ambil($tema, 'base-content');
            $sidebar = $ambil($tema, 'neutral');

            $this->assertNotSame('', $permukaan, "Tema {$label} kehilangan base-100.");

            $this->assertGreaterThanOrEqual(
                4.5,
                $kontras($teks, $permukaan),
                "Kontras teks terhadap permukaan pada tema {$label} di bawah ambang WCAG AA."
            );

            $this->assertNotSame(
                $permukaan,
                $sidebar,
                "Pada tema {$label} kartu dan sidebar berwarna identik, sehingga batas keduanya hilang."
            );

            $this->assertGreaterThan(
                $luminansi($halaman),
                $luminansi($permukaan),
                "Pada tema {$label} kartu harus lebih terang daripada halaman, supaya arah elevasi sama di kedua tema."
            );
        }
    }
}
