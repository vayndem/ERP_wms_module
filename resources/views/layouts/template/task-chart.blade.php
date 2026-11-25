<div class="card min-w-0 border border-base-300 bg-base-100 p-4 shadow-sm" data-task-chart
    data-chart-config="{{ json_encode(['type' => $chartType ?? 'bar', 'horizontal' => $horizontal ?? true, 'labels' => $tasks->pluck('label'), 'data' => $tasks->pluck('count'), 'label' => 'Jumlah']) }}">
    <h6 class="mb-2 font-bold">{{ $chartTitle ?? 'Ringkasan Tugas' }}</h6>
    <div class="relative h-[280px] min-w-0 w-full overflow-hidden"><canvas class="max-w-full"></canvas></div>
</div>
