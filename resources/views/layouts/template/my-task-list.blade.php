@php
    $kelompokTugas = collect($kelompokTugas ?? []);
    $toneClasses = [
        'error' => 'badge-error',
        'warning' => 'badge-warning',
        'info' => 'badge-info',
        'neutral' => 'badge-ghost',
    ];
    $tabAwal = $kelompokTugas->keys()->first(fn ($key) => $kelompokTugas[$key]->isNotEmpty()) ?? 'Hari ini';
@endphp
<div class="card border border-base-300 bg-base-100 shadow-sm" x-data="{ tab: @js($tabAwal) }">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 p-4">
        <div>
            <h2 class="text-lg font-bold tracking-tight"><i class="fa-solid fa-list-check text-primary"></i> Tugas Saya</h2>
            <p class="text-sm text-base-content/60">Urut berdasarkan tenggat agar prioritas berikutnya langsung terlihat.</p>
        </div>
        <span class="badge badge-primary badge-outline">{{ $jumlahTugas ?? 0 }} tugas</span>
    </div>
    <div role="tablist" class="tabs tabs-boxed mx-4 mt-4 flex-nowrap overflow-x-auto" aria-label="Kelompok tenggat tugas">
        @foreach ($kelompokTugas as $namaKelompok => $items)
            <button type="button" role="tab" class="tab shrink-0 gap-2" :class="tab === @js($namaKelompok) && 'tab-active'" :aria-selected="tab === @js($namaKelompok)" @click="tab = @js($namaKelompok)">
                {{ $namaKelompok }} <span class="badge badge-sm">{{ $items->count() }}</span>
            </button>
        @endforeach
    </div>
    <div class="mt-4 border-t border-base-300">
        @forelse ($kelompokTugas as $namaKelompok => $items)
            @if ($items->isNotEmpty())
                <section x-cloak x-show="tab === @js($namaKelompok)" x-transition.opacity.duration.150ms aria-labelledby="tugas-{{ Str::slug($namaKelompok) }}" class="motion-reduce:transition-none">
                    <div class="flex items-center justify-between bg-base-200/60 px-4 py-2">
                        <h3 id="tugas-{{ Str::slug($namaKelompok) }}" class="text-xs font-bold uppercase tracking-wider text-base-content/60">{{ $namaKelompok }}</h3>
                        <span class="text-xs font-semibold text-base-content/50">{{ $items->count() }}</span>
                    </div>
                    <div class="divide-y divide-base-300">
                        @foreach ($items as $item)
                            <div class="flex flex-col gap-3 p-4 transition-colors hover:bg-base-200/50 motion-reduce:transition-none sm:flex-row sm:items-center sm:justify-between">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="badge badge-sm badge-outline">{{ $item['jenis'] }}</span>
                                        <span class="tooltip tooltip-bottom" data-tip="{{ $item['tanggal']->format('d-m-Y') }}">
                                            <span class="badge badge-sm {{ $toneClasses[$item['tone']] }}">{{ $item['relatif'] }}</span>
                                        </span>
                                    </div>
                                    <p class="mt-2 font-semibold leading-snug">{{ $item['nomor'] }}</p>
                                    <p class="mt-1 text-sm text-base-content/60">
                                        Tenggat {{ $item['tanggal']->format('d-m-Y') }}
                                        @if (!is_null($item['nilai']))
                                            <span aria-hidden="true"> · </span>Nilai {{ rtrim(rtrim(number_format((float) $item['nilai'], 2, ',', '.'), '0'), ',') }}
                                        @endif
                                    </p>
                                </div>
                                <a href="{{ $item['url'] }}" class="btn btn-sm btn-outline btn-primary shrink-0 active:scale-[0.98] motion-reduce:transform-none">
                                    Buka dokumen <i class="fa-solid fa-arrow-right text-xs"></i>
                                </a>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endif
        @empty
        @endforelse

        @if (($jumlahTugas ?? 0) === 0)
            <div class="px-6 py-12 text-center">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-success/10 text-success">
                    <i class="fa-solid fa-check text-xl"></i>
                </span>
                <h3 class="mt-4 text-lg font-bold">Semua beres hari ini</h3>
                <p class="mx-auto mt-1 max-w-md text-sm text-base-content/60">Tidak ada tugas yang menunggu tindakan Anda. Tenggat baru akan muncul di sini saat tersedia.</p>
            </div>
        @endif
    </div>
</div>
