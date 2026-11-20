import { handleSessionExpired } from './ajax-modal';

function debounce(fn, delay) {
    let timer;
    return function (...args) {
        clearTimeout(timer);
        timer = setTimeout(() => fn.apply(this, args), delay);
    };
}

export default function wmsDataTable(config) {
    return {
        url: config.url,
        columns: config.columns || [],
        reportUrl: config.reportUrl || null,
        extraParams: config.extraParams || {},
        rows: [],
        draw: 0,
        recordsTotal: 0,
        recordsFiltered: 0,
        loading: false,
        error: null,
        search: '',
        filters: {},
        start: 0,
        length: config.pageLength || 10,
        pageLengths: [10, 20, 50, 100],
        orderColumn: config.defaultOrderColumn ?? 0,
        orderDir: config.defaultOrderDir || 'asc',
        expanded: {},

        toggleExpand(id) {
            this.expanded[id] = !this.expanded[id];
        },

        init() {
            this.fetchData();
            this.$watch('search', () => {
                this.start = 0;
                this.debouncedFetch();
            });
            this.$watch('filters', () => {
                this.start = 0;
                this.debouncedFetch();
            }, { deep: true });
            this.$watch('length', () => {
                this.start = 0;
                this.fetchData();
            });
            this.$watch('extraParams', () => {
                this.start = 0;
                this.fetchData();
            }, { deep: true });
            window.addEventListener('wms:table-refresh', () => this.fetchData());
        },

        debouncedFetch: debounce(function () {
            this.fetchData();
        }, 350),

        buildParams() {
            const params = new URLSearchParams();
            params.set('draw', String(++this.draw));
            params.set('start', String(this.start));
            params.set('length', String(this.length));
            params.set('search[value]', this.search);
            params.set('search[regex]', 'false');

            this.columns.forEach((column, index) => {
                params.set(`columns[${index}][data]`, column.data);
                params.set(`columns[${index}][name]`, column.name || column.data);
                params.set(`columns[${index}][searchable]`, column.searchable === false ? 'false' : 'true');
                params.set(`columns[${index}][orderable]`, column.orderable === false ? 'false' : 'true');
                params.set(`columns[${index}][search][value]`, this.filters[column.data] || '');
                params.set(`columns[${index}][search][regex]`, 'false');
            });

            params.set('order[0][column]', String(this.orderColumn));
            params.set('order[0][dir]', this.orderDir);

            Object.entries(this.extraParams).forEach(([key, value]) => {
                if (value !== '' && value !== null && value !== undefined) {
                    params.set(key, value);
                }
            });

            return params;
        },

        async fetchData() {
            this.loading = true;
            this.error = null;
            try {
                const params = this.buildParams();
                const response = await fetch(`${this.url}?${params.toString()}`, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                });
                if (handleSessionExpired(response)) {
                    this.rows = [];
                    return;
                }
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                const json = await response.json();
                this.rows = json.data || [];
                this.recordsTotal = json.recordsTotal ?? this.rows.length;
                this.recordsFiltered = json.recordsFiltered ?? this.rows.length;
            } catch (error) {
                this.error = 'Gagal memuat data. Silakan coba kembali.';
                this.rows = [];
                this.recordsTotal = 0;
                this.recordsFiltered = 0;
            } finally {
                this.loading = false;
            }
        },

        sortBy(index, orderable = true) {
            if (!orderable) return;
            if (this.orderColumn === index) {
                this.orderDir = this.orderDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.orderColumn = index;
                this.orderDir = 'asc';
            }
            this.fetchData();
        },

        goToPage(page) {
            const clamped = Math.max(0, Math.min(page, this.pageCount - 1));
            this.start = clamped * this.length;
            this.fetchData();
        },

        get currentPage() {
            return Math.floor(this.start / this.length);
        },

        get pageCount() {
            return Math.max(1, Math.ceil(this.recordsFiltered / this.length));
        },

        get rangeStart() {
            return this.recordsFiltered === 0 ? 0 : this.start + 1;
        },

        get rangeEnd() {
            return Math.min(this.start + this.length, this.recordsFiltered);
        },

        buildReportUrl(format = 'pdf') {
            if (!this.reportUrl) return '#';
            const base = format === 'pdf' ? this.reportUrl : this.reportUrl.replace(/\/pdf$/, '/' + format);
            const url = new URL(base, window.location.origin);
            if (this.search) url.searchParams.set('search', this.search);
            Object.entries(this.filters).forEach(([key, value]) => {
                if (value) url.searchParams.set(`filters[${key}]`, value);
            });
            document.querySelectorAll('[id^="filter_"]').forEach((control) => {
                const isAllMonths = control.id === 'filter_bulan' && control.value === '0';
                if (control.value !== '' && !isAllMonths) {
                    url.searchParams.set(control.id.replace(/^filter_/, ''), control.value);
                }
            });
            return url.toString();
        },
    };
}
