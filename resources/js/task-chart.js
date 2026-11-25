import Chart from 'chart.js/auto';

const PALETTE = ['#2563eb', '#f59e0b', '#dc2626', '#16a34a', '#7c3aed', '#0891b2', '#db2777', '#65a30d'];

function tokenHsl(nama, alpha) {
    const nilai = getComputedStyle(document.documentElement).getPropertyValue(nama).trim();

    return nilai ? `hsl(${nilai} / ${alpha})` : null;
}

function warnaTema() {
    return {
        teks: tokenHsl('--bc', 0.75) || '#64748b',
        teksRedup: tokenHsl('--bc', 0.55) || '#94a3b8',
        grid: tokenHsl('--bc', 0.12) || 'rgba(100, 116, 139, 0.2)',
        tooltipLatar: tokenHsl('--n', 0.95) || '#0f172a',
        tooltipTeks: tokenHsl('--nc', 1) || '#e2e8f0',
        garisTepi: tokenHsl('--b1', 1) || '#ffffff',
    };
}

function terapkanWarna(chart, warna) {
    const skala = chart.options.scales || {};

    Object.values(skala).forEach((sumbu) => {
        if (!sumbu || typeof sumbu !== 'object') return;
        sumbu.ticks.color = warna.teksRedup;
        sumbu.grid.color = warna.grid;
        sumbu.grid.borderColor = warna.grid;
        sumbu.border.color = warna.grid;
    });

    chart.options.plugins.legend.labels.color = warna.teks;
    chart.options.plugins.tooltip.backgroundColor = warna.tooltipLatar;
    chart.options.plugins.tooltip.titleColor = warna.tooltipTeks;
    chart.options.plugins.tooltip.bodyColor = warna.tooltipTeks;
    chart.options.plugins.tooltip.borderColor = warna.grid;
    chart.options.plugins.tooltip.borderWidth = 1;

    chart.data.datasets.forEach((dataset) => {
        if (dataset.type === 'line' || chart.config.type === 'line') return;
        dataset.borderColor = warna.garisTepi;
        dataset.borderWidth = chart.config.type === 'doughnut' || chart.config.type === 'pie' ? 2 : 0;
    });
}

export default function initTaskCharts() {
    document.querySelectorAll('[data-task-chart]').forEach((wadah) => {
        const config = JSON.parse(wadah.dataset.chartConfig || '{}');
        const canvas = wadah.querySelector('canvas');

        if (!canvas) return;

        const labels = config.labels || [];
        const data = config.data || [];
        const type = config.type || 'bar';
        const warna = warnaTema();
        const chart = new Chart(canvas, {
                type,
                data: {
                    labels,
                    datasets: [{
                        label: config.label || 'Jumlah',
                        data,
                        backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]),
                        borderRadius: type === 'bar' ? 6 : 0,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: config.horizontal ? 'y' : 'x',
                    color: warna.teks,
                    plugins: {
                        legend: { display: type !== 'bar' && type !== 'line', position: 'bottom' },
                    },
                    scales: (type === 'bar' || type === 'line') ? {
                        x: config.horizontal ? { beginAtZero: true, ticks: { precision: 0 } } : {},
                        y: config.horizontal ? {} : { beginAtZero: config.beginAtZero !== false, ticks: { precision: 0 } },
                    } : {},
                },
        });

        terapkanWarna(chart, warna);
        chart.update('none');

        const pendengarTema = () => {
            const baru = warnaTema();
            chart.options.color = baru.teks;
            terapkanWarna(chart, baru);
            chart.update('none');
        };

        window.addEventListener('inventory:theme-changed', pendengarTema, { passive: true });
    });
}
