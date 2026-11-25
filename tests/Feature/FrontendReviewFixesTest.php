<?php

namespace Tests\Feature;

use Tests\TestCase;

class FrontendReviewFixesTest extends TestCase
{
    public function test_aksi_bukti_potong_meminta_konfirmasi_coretax(): void
    {
        $view = file_get_contents(resource_path('views/bukti_potong/show.blade.php'));

        $this->assertSame(2, substr_count($view, '@submit.prevent="AppAlert.confirm('));
        $this->assertSame(2, substr_count($view, 'juga harus dibatalkan di Coretax'));
        $this->assertSame(2, substr_count($view, '$el.submit()'));
    }

    public function test_field_faktur_pajak_mengikuti_toggle_ppn(): void
    {
        $create = file_get_contents(resource_path('views/faktur_pembelian/create.blade.php'));
        $edit = file_get_contents(resource_path('views/faktur_pembelian/edit.blade.php'));

        $this->assertSame(2, substr_count($create, ':disabled="!form.is_ppn"'));
        $this->assertStringContainsString("this.form.no_faktur_pajak = '';", $create);
        $this->assertStringContainsString("this.form.tanggal_faktur_pajak = '';", $create);
        $this->assertSame(2, substr_count($edit, ':disabled="!isPpn"'));
        $this->assertStringContainsString("this.noFakturPajak = '';", $edit);
        $this->assertStringContainsString("this.tanggalFakturPajak = '';", $edit);
    }

    public function test_fetch_modal_mendeteksi_sesi_habis_sebelum_membaca_body(): void
    {
        $create = file_get_contents(resource_path('views/faktur_pembelian/create.blade.php'));
        $edit = file_get_contents(resource_path('views/faktur_pembelian/edit.blade.php'));

        $this->assertSame(2, substr_count($create, 'window.handleSessionExpired(response)'));
        $this->assertSame(1, substr_count($edit, 'window.handleSessionExpired(response)'));
        $this->assertMatchesRegularExpression('/handleSessionExpired\(response\).*?response\.json\(\)/s', $create);
        $this->assertMatchesRegularExpression('/handleSessionExpired\(response\).*?response\.json\(\)/s', $edit);
    }

    public function test_status_pajak_memakai_label_indonesia_terpusat(): void
    {
        $labels = file_get_contents(resource_path('views/bukti_potong/_status-label.blade.php'));
        $index = file_get_contents(resource_path('views/bukti_potong/index.blade.php'));
        $laporan = file_get_contents(resource_path('views/laporan_pajak_djp/index.blade.php'));

        foreach (['Semua', 'Draf', 'Diposting', 'Diterbitkan', 'Dibatalkan', 'Diganti'] as $label) {
            $this->assertStringContainsString("=> '{$label}'", $labels);
        }

        $this->assertStringContainsString('value="{{ $opsi }}"', $index);
        $this->assertGreaterThanOrEqual(3, substr_count($index, "bukti_potong._status-label"));
        $this->assertGreaterThanOrEqual(2, substr_count($laporan, "bukti_potong._status-label"));
    }

    public function test_modal_tidak_memotong_konten_dan_empty_state_pelanggan_span_penuh(): void
    {
        foreach ([
            'views/faktur_pembelian/create.blade.php',
            'views/faktur_pembelian/edit.blade.php',
            'views/faktur_pembelian/index.blade.php',
            'views/supplier/create.blade.php',
            'views/supplier/edit.blade.php',
        ] as $path) {
            $view = file_get_contents(resource_path($path));
            $this->assertDoesNotMatchRegularExpression('/modal-box[^\"]*overflow-hidden/', $view);

            if (str_contains($path, 'faktur_pembelian/create') || str_contains($path, 'faktur_pembelian/edit')) {
                $this->assertStringNotContainsString('overflow-y-auto p-6', $view);
            }
        }

        $pelanggan = file_get_contents(resource_path('views/pelanggan/index.blade.php'));
        $this->assertStringContainsString('<td colspan="11"', $pelanggan);
    }

    public function test_ringkasan_pajak_memakai_komponen_stats_daisyui(): void
    {
        $laporan = file_get_contents(resource_path('views/laporan_pajak_djp/index.blade.php'));

        $this->assertStringContainsString('stats stats-vertical', $laporan);
        $this->assertSame(4, preg_match_all('/<div class="stat(?:\s|\")/', $laporan));
        $this->assertSame(4, substr_count($laporan, 'stat-title'));
        $this->assertSame(4, substr_count($laporan, 'stat-value'));
    }

    public function test_form_memiliki_guard_submit_ganda_dan_umpan_balik_validasi(): void
    {
        $script = file_get_contents(resource_path('js/form-ui.js'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString("form.dataset.wmsSubmitting === 'true'", $script);
        $this->assertStringContainsString('Memproses...', $script);
        $this->assertStringContainsString('data-field-error', $layout);
        $this->assertStringContainsString("textContent = ' (wajib)'", $script);
        $this->assertStringContainsString("field.setAttribute('aria-invalid', 'true')", $script);
    }

    public function test_aksi_destruktif_tidak_memakai_confirm_native(): void
    {
        $paths = array_merge(
            glob(resource_path('views/**/*.blade.php')) ?: [],
            glob(resource_path('js/*.js')) ?: [],
        );

        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('return confirm(', $source, $path);
        }
    }

    public function test_dashboard_dan_tab_laporan_tidak_melebarkan_halaman_mobile(): void
    {
        $chart = file_get_contents(resource_path('js/task-chart.js'));
        $metric = file_get_contents(resource_path('views/layouts/template/metric-chart.blade.php'));
        $dashboard = file_get_contents(resource_path('views/layouts/template/erp-dashboard.blade.php'));
        $tabs = file_get_contents(resource_path('views/financial_statements/_tabs.blade.php'));

        $this->assertStringNotContainsString('chart.options.plugins = chart.options.plugins', $chart);
        $this->assertStringContainsString('min-w-0 w-full overflow-hidden', $metric);
        $this->assertStringContainsString('stats overflow-hidden', $dashboard);
        $this->assertStringContainsString('max-w-full flex-nowrap overflow-x-auto', $tabs);
    }
}
