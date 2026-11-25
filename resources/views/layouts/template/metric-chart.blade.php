<div class="card min-w-0 border border-base-300 bg-base-100 p-4 shadow-sm" data-task-chart
    data-chart-config="{{ json_encode(['type' => $chartType ?? 'bar', 'horizontal' => $horizontal ?? false, 'beginAtZero' => $beginAtZero ?? true, 'labels' => $labels, 'data' => $data, 'label' => $seriesLabel ?? 'Nilai']) }}">
    <h6 class="mb-2 font-bold">{{ $chartTitle ?? 'Grafik' }}</h6>
    <div class="relative h-[280px] min-w-0 w-full overflow-hidden"><canvas class="max-w-full"></canvas></div>
</div>
