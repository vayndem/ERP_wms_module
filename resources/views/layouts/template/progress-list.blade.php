@php
    $progress = collect($progress ?? []);
    $judul = $judul ?? 'Progres Pekerjaan';
@endphp
@if ($progress->isNotEmpty())
    <div class="card border border-base-300 bg-base-100 shadow-sm">
        <div class="border-b border-base-300 p-4">
            <h5 class="font-bold"><i class="fa-solid fa-bars-progress text-primary"></i> {{ $judul }}</h5>
        </div>
        <div class="space-y-4 p-4">
            @foreach ($progress as $item)
                @php
                    $persen = (float) $item['persen'];
                    $tone = match (true) {
                        $persen >= 80 => 'progress-success',
                        $persen >= 50 => 'progress-info',
                        $persen >= 25 => 'progress-warning',
                        default => 'progress-error',
                    };
                    $radialTone = match (true) {
                        $persen >= 80 => 'text-success',
                        $persen >= 50 => 'text-info',
                        $persen >= 25 => 'text-warning',
                        default => 'text-error',
                    };
                @endphp
                <div class="flex items-center gap-4">
                    <div class="radial-progress shrink-0 {{ $radialTone }}" style="--value:{{ $persen }}; --size:3.5rem; --thickness:0.35rem;" role="progressbar" aria-valuenow="{{ $persen }}" aria-valuemin="0" aria-valuemax="100">
                        <span class="text-xs font-bold">{{ rtrim(rtrim(number_format($persen, 1, ',', '.'), '0'), ',') }}%</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="mb-1 flex items-end justify-between gap-3">
                            <span class="text-sm font-semibold">{{ $item['label'] }}</span>
                        </div>
                        <progress class="progress {{ $tone }} w-full" value="{{ $persen }}" max="100"></progress>
                        <p class="mt-1 text-xs text-base-content/60">
                            {{ rtrim(rtrim(number_format((float) $item['selesai'], 2, ',', '.'), '0'), ',') }}
                            dari
                            {{ rtrim(rtrim(number_format((float) $item['total'], 2, ',', '.'), '0'), ',') }}
                            @if ($item['catatan']) &middot; {{ $item['catatan'] }} @endif
                        </p>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
@endif
