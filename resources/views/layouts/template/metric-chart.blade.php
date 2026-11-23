<div class="card border border-base-300 bg-base-100 p-4 shadow-sm" data-task-chart
    data-chart-config="{{ json_encode(['type' => $chartType ?? 'bar', 'horizontal' => $horizontal ?? false, 'beginAtZero' => $beginAtZero ?? true, 'labels' => $labels, 'data' => $data, 'label' => $seriesLabel ?? 'Nilai']) }}">
    <h6 class="mb-2 font-bold">{{ $chartTitle ?? 'Grafik' }}</h6>
    <div style="height: 280px;"><canvas></canvas></div>
</div>
