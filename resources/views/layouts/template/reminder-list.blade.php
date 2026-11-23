@php
    $reminders = collect($reminders ?? []);
    $judul = $judul ?? 'Pengingat & Deadline';
@endphp
<div class="card border border-base-300 bg-base-100 shadow-sm">
    <div class="flex items-center justify-between border-b border-base-300 p-4">
        <h5 class="font-bold"><i class="fa-solid fa-bell text-warning"></i> {{ $judul }}</h5>
        @if ($reminders->isNotEmpty())
            <span class="badge badge-warning">{{ $reminders->count() }}</span>
        @endif
    </div>
    <div class="p-4">
        <ul class="timeline timeline-vertical timeline-compact">
        @forelse ($reminders as $item)
            @php
                $hari = (int) $item['hari'];
                [$tone, $teks] = match (true) {
                    $hari < 0 => ['error', 'Lewat ' . abs($hari) . ' hari'],
                    $hari === 0 => ['error', 'Hari ini'],
                    $hari <= 7 => ['warning', $hari . ' hari lagi'],
                    default => ['info', $hari . ' hari lagi'],
                };
            @endphp
            <li>
                @if (!$loop->first)<hr class="bg-base-300">@endif
                <div class="timeline-start pr-2 text-end">
                    <span class="badge badge-{{ $tone }} badge-sm whitespace-nowrap">{{ $teks }}</span>
                    <span class="mt-1 block text-xs text-base-content/50">{{ $item['tanggal']->format('d-m-Y') }}</span>
                </div>
                <div class="timeline-middle text-base-content/40">
                    <i class="fa-solid fa-circle text-xs"></i>
                </div>
                <a href="{{ $item['url'] }}" class="timeline-end timeline-box mb-3 block w-full border-base-300 bg-base-100 transition hover:border-primary hover:bg-base-200/50 motion-reduce:transition-none">
                    <p class="text-sm font-semibold leading-snug">{{ $item['label'] }}</p>
                    <p class="mt-1 text-xs text-base-content/60">
                        {{ $item['konteks'] }}
                        @if (!is_null($item['nilai']))
                            &middot; {{ rtrim(rtrim(number_format((float) $item['nilai'], 2, ',', '.'), '0'), ',') }}
                        @endif
                    </p>
                </a>
                @if (!$loop->last)<hr class="bg-base-300">@endif
            </li>
        @empty
            <li class="block p-6 text-center text-base-content/50">
                <i class="fa-solid fa-circle-check text-2xl text-success"></i>
                <p class="mt-2 text-sm">Tidak ada yang mendesak. Semua aman.</p>
            </li>
        @endforelse
        </ul>
    </div>
</div>
