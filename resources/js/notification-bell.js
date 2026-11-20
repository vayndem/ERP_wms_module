import { handleSessionExpired } from './ajax-modal';

export default function notifikasiLonceng() {
    return {
        belumDibaca: 0,
        items: [],
        memuat: false,
        sudahDimuat: false,

        init() {
            this.ambil();
        },

        muat() {
            if (!this.sudahDimuat) {
                this.ambil();
            }
        },

        async ambil() {
            this.memuat = true;
            try {
                const response = await fetch('/notifikasi/lonceng', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                });
                if (handleSessionExpired(response)) return;
                if (!response.ok) throw new Error('HTTP ' + response.status);
                const json = await response.json();
                this.belumDibaca = json.belum_dibaca ?? 0;
                this.items = json.items ?? [];
                this.sudahDimuat = true;
            } catch (error) {
                this.items = [];
            } finally {
                this.memuat = false;
            }
        },

        async bacaSemua() {
            const token = document.querySelector('meta[name="csrf-token"]')?.content;
            try {
                const response = await fetch('/notifikasi/baca-semua', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': token,
                        Accept: 'application/json',
                    },
                });
                if (handleSessionExpired(response)) return;
                this.belumDibaca = 0;
                this.items = this.items.map((item) => ({ ...item, dibaca: true }));
            } catch (error) {
                this.ambil();
            }
        },
    };
}
