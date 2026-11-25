<div class="card border border-base-300 bg-base-100 p-4 shadow-sm" data-task-chart
    data-chart-config="{{ json_encode(['type' => $chartType ?? 'bar', 'horizontal' => $horizontal ?? true, 'labels' => $tasks->pluck('label'), 'data' => $tasks->pluck('count'), 'label' => 'Jumlah']) }}">
    <h6 class="mb-2 font-bold">{{ $chartTitle ?? 'Ringkasan Tugas' }}</h6>
    <div style="height: 280px;"><canvas></canvas></div>
</div>
