<dialog id="editInvoiceModal" class="modal">
    <div class="modal-box max-w-5xl p-0" x-data="{
        isPpn: {{ $invoice->ppn > 0 ? 'true' : 'false' }},
        noFakturPajak: @js($invoice->no_faktur_pajak ?? ''),
        tanggalFakturPajak: @js($invoice->tanggal_faktur_pajak?->format('Y-m-d') ?? ''),
        diskon: {{ $invoice->diskon }},
        ongkir: {{ $invoice->ongkir }},
        ppnImpor: {{ $invoice->ppn_impor }},
        subTotal: {{ $invoice->sub_total }},
        submitting: false,
        jenisPph: @js($invoice->jenis_pph ?? ''),
        kop: @js($invoice->kode_objek_pajak ?? ''),
        kodeObjek: @js($kodeObjek),
        get kodeObjekTersaring() { return this.kodeObjek.filter((item) => item.jenis_pph === this.jenisPph); },
        get ppnNominal() { return this.isPpn ? Math.round((this.subTotal * 11) / 100) : 0; },
        get grandTotal() { return (this.subTotal + this.ppnNominal + (Number(this.ongkir) || 0) + (Number(this.ppnImpor) || 0)) - (Number(this.diskon) || 0); },
        init() {
            if (!this.isPpn) {
                this.noFakturPajak = '';
                this.tanggalFakturPajak = '';
            }
            this.$watch('isPpn', (enabled) => {
                if (!enabled) {
                    this.noFakturPajak = '';
                    this.tanggalFakturPajak = '';
                }
            });
        },
        async submit(event) {
            const form = event.target;
            if (this.isPpn && !/^(\d{17}|\d{3}\.\d{3}-\d{2}\.\d{8})$/.test((form.no_faktur_pajak.value || '').trim())) {
                window.AppAlert.warning('Nomor Faktur Pajak wajib diisi dengan format yang benar saat PPN dipakai.');
                return;
            }
            this.submitting = true;
            try {
                const method = form.querySelector('input[name="_method"]')?.value.toUpperCase() || 'POST';
                const response = await fetch(form.action, {
                    method,
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: new URLSearchParams(new FormData(form)),
                });
                if (window.handleSessionExpired(response)) return;
                const data = await response.json().catch(() => ({}));
                if (!response.ok) { window.AppAlert.ajaxError(data); return; }
                window.AppAlert.auto(data);
                window.dispatchEvent(new CustomEvent('wms:table-refresh'));
                form.closest('dialog').close();
            } catch (error) {
                window.AppAlert.error('Gagal memperbarui invoice.');
            } finally {
                this.submitting = false;
            }
        },
    }">
        <div class="bg-warning px-6 py-4 text-warning-content">
            <h3 class="text-lg font-bold"><i class="fa-solid fa-pen-to-square"></i> Edit Invoice LPB ({{ $invoice->no_invoice }})</h3>
        </div>
        <form action="{{ route('faktur-pembelian.update', $invoice->id) }}" method="POST" @submit.prevent="submit($event)"
            data-autosave data-autosave-key="invoice-lpb-edit-{{ $invoice->id }}" class="flex flex-col">
            @csrf
            @method('PUT')
            <input type="hidden" name="kode_supplier" value="{{ $invoice->kode_supplier }}">

            <div class="p-6">
                <div class="card border border-base-300 bg-base-100 p-4 shadow-sm">
                    <div class="grid grid-cols-1 gap-4">
                        <div class="form-control">
                            <label class="label"><span class="label-text font-semibold">LPB dalam Invoice</span></label>
                            <select class="select select-bordered" name="lpb_ids[]" multiple required data-app-picker data-placeholder="Cari dan pilih LPB/BAP supplier...">
                                @foreach ($lpbs as $lpb)
                                    <option value="{{ $lpb->id }}" @selected($invoice->lpbs->contains('id', $lpb->id))>
                                        {{ $lpb->id_lpb }} — {{ $lpb->pembelian->supplier->nama ?? '-' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <div class="form-control">
                                <label class="label"><span class="label-text font-semibold">No. Invoice Supplier <span class="text-error">*</span></span></label>
                                <input type="text" class="input input-bordered" name="no_invoice" value="{{ $invoice->no_invoice }}" required>
                            </div>
                            <div class="form-control">
                                <label class="label"><span class="label-text font-semibold">Supplier</span></label>
                                <input type="text" class="input input-bordered bg-base-200" value="{{ $invoice->supplier->nama ?? '-' }}" readonly>
                            </div>
                            <div class="form-control">
                                <label class="label"><span class="label-text font-semibold">Tanggal Invoice <span class="text-error">*</span></span></label>
                                <input type="date" class="input input-bordered" name="tanggal" value="{{ $invoice->tanggal->format('Y-m-d') }}" required>
                            </div>
                            <div class="form-control">
                                <label class="label"><span class="label-text font-semibold">Deadline Pembayaran</span></label>
                                <input type="date" class="input input-bordered" name="tgl_deadline_pembayaran" value="{{ optional($invoice->tgl_deadline_pembayaran)->format('Y-m-d') }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="card border border-base-300 bg-base-100 p-4 shadow-sm">
                        <label class="label"><span class="label-text font-semibold">Catatan / Note</span></label>
                        <textarea class="textarea textarea-bordered h-full" name="note" rows="5" placeholder="Catatan opsional...">{{ $invoice->note }}</textarea>
                    </div>
                    <div class="card border border-base-300 bg-base-100 p-4 shadow-sm">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="font-semibold text-base-content/60">Sub Total:</span>
                            <span class="text-lg font-bold">Rp {{ number_format($invoice->sub_total, 0, ',', '.') }}</span>
                        </div>
                        <div class="mb-2 flex items-center justify-between">
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" name="is_ppn" value="1" x-model="isPpn" class="checkbox">
                                <span class="font-semibold">Gunakan PPN (11%)</span>
                            </label>
                            <span class="font-semibold text-base-content/60" x-text="'Rp ' + ppnNominal.toLocaleString('id-ID')"></span>
                        </div>
                        <div x-show="isPpn" x-cloak class="mb-2">
                            <label class="label"><span class="label-text font-semibold">No. Faktur Pajak (NSFP) <span class="text-error">*</span></span></label>
                            <input type="text" class="input input-bordered input-sm w-full" name="no_faktur_pajak" x-model="noFakturPajak" :disabled="!isPpn" :required="isPpn"
                                placeholder="17 digit angka" pattern="(\d{17}|\d{3}\.\d{3}-\d{2}\.\d{8})">
                            <span class="label-text-alt mt-1 text-base-content/50">Format: 17 digit angka (atau format lama 010.001-23.12345678) — wajib diisi bila PPN dipakai, syarat kredit PPN Masukan.</span>
                            <label class="label"><span class="label-text font-semibold">Tanggal Faktur Pajak</span></label>
                            <input type="date" class="input input-bordered input-sm w-full" name="tanggal_faktur_pajak" x-model="tanggalFakturPajak" :disabled="!isPpn">
                        </div>
                        <div class="mb-2 flex items-center gap-3">
                            <label class="w-24 font-semibold">Diskon:</label>
                            <input type="number" step="any" min="0" name="diskon" x-model.number="diskon" data-money-input class="input input-bordered input-sm flex-1 text-end">
                        </div>
                        <div class="mb-2 flex items-center gap-3">
                            <label class="w-24 font-semibold">Ongkir:</label>
                            <input type="number" step="any" min="0" name="ongkir" x-model.number="ongkir" data-money-input class="input input-bordered input-sm flex-1 text-end">
                        </div>
                        <div class="mb-2 flex items-center gap-3">
                            <label class="w-24 font-semibold">PPN Impor:</label>
                            <input type="number" step="any" min="0" name="ppn_impor" x-model.number="ppnImpor" data-money-input class="input input-bordered input-sm flex-1 text-end">
                        </div>
                        <div class="mb-2 flex items-center gap-3">
                            <label class="w-24 font-semibold">PPnBM:</label>
                            <input type="number" step="any" min="0" name="ppnbm" value="{{ $invoice->ppnbm }}" data-money-input class="input input-bordered input-sm flex-1 text-end">
                        </div>
                        <div class="mb-2">
                            <label class="label"><span class="label-text font-semibold">Mata Uang Asing (opsional)</span></label>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                <select name="mata_uang_asing" class="select select-bordered select-sm">
                                    <option value="" @selected(!$invoice->mata_uang_asing)>Tidak ada (IDR)</option>
                                    @foreach (['USD', 'EUR', 'SGD', 'JPY', 'CNY'] as $currency)
                                        <option value="{{ $currency }}" @selected($invoice->mata_uang_asing === $currency)>{{ $currency }}</option>
                                    @endforeach
                                </select>
                                <input type="number" step="any" min="0.0001" name="kurs" value="{{ $invoice->kurs }}" class="input input-bordered input-sm" placeholder="Kurs">
                                <input type="number" step="any" min="0.01" name="nilai_asing" value="{{ $invoice->nilai_asing }}" class="input input-bordered input-sm" placeholder="Nilai asing">
                            </div>
                            <span class="label-text-alt mt-1 text-base-content/50">Catatan referensi nilai asli invoice supplier dalam mata uang asing; seluruh perhitungan sistem tetap dalam Rupiah.</span>
                        </div>
                        <div class="mb-2">
                            <label class="label"><span class="label-text font-semibold">Jenis PPh</span></label>
                            <select name="jenis_pph" x-model="jenisPph" @change="kop = ''" class="select select-bordered select-sm w-full">
                                <option value="" @selected(!$invoice->jenis_pph)>Tidak ada</option>
                                <option value="PPH23" @selected($invoice->jenis_pph === 'PPH23')>PPh 23</option>
                                <option value="PPH22" @selected($invoice->jenis_pph === 'PPH22')>PPh 22</option>
                                <option value="PPH4A2" @selected($invoice->jenis_pph === 'PPH4A2')>PPh 4(2) Final</option>
                            </select>
                            <span class="label-text-alt mt-1 text-base-content/50">PPh dicatat dan dipotong saat pembayaran.</span>
                        </div>
                        <div class="mb-2" x-show="jenisPph" x-cloak>
                            <label class="label"><span class="label-text font-semibold">Kode Objek Pajak (opsional)</span></label>
                            <select name="kode_objek_pajak" x-model="kop" class="select select-bordered select-sm w-full">
                                <option value="">Belum dipilih</option>
                                <template x-for="item in kodeObjekTersaring" :key="item.kode">
                                    <option :value="item.kode" x-text="item.kode + ' - ' + item.deskripsi"></option>
                                </template>
                            </select>
                        </div>
                        <div class="divider my-1"></div>
                        <div class="flex items-center justify-between">
                            <h5 class="text-lg font-bold">Grand Total:</h5>
                            <h5 class="text-lg font-bold text-primary" x-text="'Rp ' + grandTotal.toLocaleString('id-ID')"></h5>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-base-300 bg-base-100 px-6 py-4">
                <button type="button" class="btn btn-ghost" onclick="closeAjaxModal(this)">Batal</button>
                <button type="submit" class="btn btn-warning" :disabled="submitting">
                    <span x-show="submitting" class="loading loading-spinner loading-sm"></span>
                    <i class="fa-solid fa-rotate" x-show="!submitting"></i> Perbarui Invoice
                </button>
            </div>
        </form>
    </div>
</dialog>
