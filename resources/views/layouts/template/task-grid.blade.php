@php
    $toneClasses = [
        'error' => 'bg-error/10 text-error',
        'warning' => 'bg-warning/10 text-warning',
        'info' => 'bg-info/10 text-info',
        'success' => 'bg-success/10 text-success',
        'neutral' => 'bg-base-200 text-base-content/60',
    ];
@endphp
<div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
    @forelse ($tasks as $task)
        <a href="{{ $task['url'] }}" class="stats border border-base-300 bg-base-100 shadow-sm transition hover:border-primary hover:shadow-md motion-reduce:transition-none">
            <div class="stat p-4">
                <div class="stat-figure">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full {{ $toneClasses[$task['tone'] ?? 'neutral'] }}">
                        <i class="fa-solid {{ $task['icon'] ?? 'fa-list-check' }}"></i>
                    </span>
                </div>
                <div class="stat-title whitespace-normal">{{ $task['label'] }}</div>
                <div class="stat-value text-2xl">{{ $task['count'] }}</div>
                <div class="stat-desc">Buka daftar terkait</div>
            </div>
        </a>
    @empty
        <div class="col-span-full py-4 text-center text-base-content/50">Tidak ada tugas untuk ditampilkan.</div>
    @endforelse
</div>
