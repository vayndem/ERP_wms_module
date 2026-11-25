@php
    $kpiToneClasses = [
        'error' => 'bg-error/10 text-error',
        'warning' => 'bg-warning/10 text-warning',
        'info' => 'bg-info/10 text-info',
        'success' => 'bg-success/10 text-success',
        'neutral' => 'bg-base-200 text-base-content/70',
    ];
@endphp
<div class="content-page" x-data="{ loading: true, error: false }" x-init="requestAnimationFrame(() => loading = false)">
    <div x-show="loading" role="status" class="space-y-3" aria-live="polite">
        <div class="skeleton h-28 w-full"></div>
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="skeleton h-28"></div>
            <div class="skeleton h-28"></div>
            <div class="skeleton h-28"></div>
            <div class="skeleton h-28"></div>
        </div>
        <p class="text-sm text-base-content/60">Memuat prioritas kerja Anda…</p>
    </div>

    <div x-cloak x-show="error" role="alert" class="card border border-error/30 bg-base-100 shadow-sm">
        <div class="card-body items-center text-center">
            <i class="fa-solid fa-circle-exclamation text-2xl text-error"></i>
            <h2 class="card-title">Dashboard belum dapat dimuat</h2>
            <p class="text-base-content/60">Coba muat ulang halaman. Jika tetap terjadi, hubungi administrator.</p>
            <button type="button" class="btn btn-outline btn-error btn-sm" @click="window.location.reload()">Muat ulang</button>
        </div>
    </div>

    <div x-cloak x-show="!loading && !error" x-transition.opacity.duration.200ms class="space-y-5 motion-reduce:transition-none">
        <header class="card overflow-hidden border border-base-300 bg-base-100 shadow-sm">
            <div class="card-body gap-4 p-5 sm:p-6">
                <div class="breadcrumbs py-0 text-xs text-base-content/50">
                    <ul>
                        <li>Dashboard</li>
                        <li>{{ $dashboard['nama_peran'] }}</li>
                    </ul>
                </div>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div class="flex items-start gap-3 sm:items-center">
                        <div class="avatar placeholder hidden sm:block">
                            <div class="w-12 rounded-full bg-primary text-primary-content">
                                <span class="text-lg font-bold">{{ Str::upper(Str::substr($user->name, 0, 1)) }}</span>
                            </div>
                        </div>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-primary">{{ $dashboard['judul_lama'] }}</p>
                            <h1 class="mt-1 text-2xl font-bold leading-tight tracking-tight sm:text-3xl">Selamat datang, {{ $user->name }}</h1>
                            <p class="mt-2 max-w-2xl text-sm leading-relaxed text-base-content/60">{{ $dashboard['deskripsi'] }}</p>
                        </div>
                    </div>
                    <div class="sm:text-right">
                        <p class="text-sm font-semibold">{{ $dashboard['tanggal'] }}</p>
                        <p class="mt-1 text-sm text-base-content/60">{{ $dashboard['ringkasan'] }}</p>
                    </div>
                </div>
            </div>
        </header>

        <section aria-labelledby="ringkasan-kpi">
            <div class="mb-2 flex items-center justify-between">
                <h2 id="ringkasan-kpi" class="text-sm font-bold uppercase tracking-wider text-base-content/60">Ringkasan {{ $dashboard['nama_peran'] }}</h2>
                <span class="text-xs text-base-content/50">Klik kartu untuk menindaklanjuti</span>
            </div>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
                @forelse ($dashboard['kpi'] as $item)
                    <a href="{{ $item['url'] }}" class="stats overflow-hidden border border-base-300 bg-base-100 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-primary hover:shadow-md active:translate-y-0 motion-reduce:transform-none motion-reduce:transition-none">
                        <div class="stat p-4">
                            <div class="stat-figure">
                                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl {{ $kpiToneClasses[$item['tone'] ?? 'neutral'] }}">
                                    <i class="fa-solid {{ $item['icon'] ?? 'fa-list-check' }}"></i>
                                </span>
                            </div>
                            <div class="stat-title whitespace-normal">{{ $item['label'] }}</div>
                            <div class="stat-value text-3xl">{{ number_format((float) $item['count'], 0, ',', '.') }}</div>
                            <div class="stat-desc tooltip tooltip-right w-fit" data-tip="Buka daftar dan tindak lanjuti">Buka daftar terkait <i class="fa-solid fa-arrow-right ml-1"></i></div>
                        </div>
                    </a>
                @empty
                    <div class="col-span-full rounded-box border border-base-300 bg-base-100 p-5 text-sm text-base-content/60">Belum ada ringkasan untuk peran ini.</div>
                @endforelse
            </div>
        </section>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-12">
            <div class="xl:col-span-7">
                @include('layouts.template.my-task-list', [
                    'kelompokTugas' => $dashboard['kelompok_tugas'],
                    'jumlahTugas' => $dashboard['jumlah_tugas'],
                ])
            </div>
            <div class="xl:col-span-5">
                @include('layouts.template.reminder-list', ['reminders' => $reminders ?? [], 'judul' => 'Tenggat Terdekat'])
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            @include('layouts.template.progress-list', ['progress' => $progress ?? [], 'judul' => 'Progres Pekerjaan'])
            @include('layouts.template.metric-chart', [
                'labels' => $dashboard['grafik']['labels'],
                'data' => $dashboard['grafik']['data'],
                'chartTitle' => 'Beban Kerja Berdasarkan Tenggat',
                'chartType' => 'bar',
                'seriesLabel' => 'Jumlah tugas',
            ])
        </div>
    </div>
</div>
