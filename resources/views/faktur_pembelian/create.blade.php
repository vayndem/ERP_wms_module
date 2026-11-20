<dialog id="createInvoiceModal" class="modal">
    <div class="modal-box max-w-6xl p-0 overflow-hidden" x-data="invoiceCreateForm({
        suppliers: {{ Js::from($suppliers->map(fn($s) => ['id' => $s->id, 'nama' => $s->nama, 'telp' => $s->telp, 'alamat' => $s->alamat])) }},
        lpbs: {{ Js::from($lpbs->map(fn($l) => [
                'id' => $l->id,
                'code' => $l->id_lpb,
                'supplier_id' => $l->pembelian->supplier_id ?? '',
                'type' => $l->document_type === 'SERVICE_BAP' ? 'BAP Jasa' : 'LPB Barang',
                'date' => $l->tanggal?->format('d-m-Y'),
                'po' => $l->no_po,
            ])) }},
    })">
        <div class="bg-primary px-6 py-4 text-primary-content">
            <h3 class="text-lg font-bold"><i class="fa-solid fa-file-invoice-dollar"></i> Buat Invoice Supplier Baru</h3>
        </div>
        <form @submit.prevent="submit()" data-autosave data-autosave-key="invoice-lpb-create" class="flex flex-col">
            <div class="max-h-[75vh] overflow-y-auto p-6">
                <div class="card border border-base-300 bg-base-100 p-4 shadow-sm">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <div class="form-control relative">
                            <label class="label"><span class="label-text font-semibold">1. Pilih Supplier <span class="text-error">*</span></span></label>
                            <button type="button" class="select select-bordered text-left" @click="supplierPickerOpen = !supplierPickerOpen">
                                <span x-text="selectedSupplier ? selectedSupplier.nama : 'Cari dan pilih supplier'"></span>
                            </button>
                            <div x-show="supplierPickerOpen" x-cloak @click.outside="supplierPickerOpen = false"
                                class="absolute top-full z-30 mt-1 w-full rounded-box border border-base-300 bg-base-100 p-2 shadow-lg">
                                <input type="search" class="input input-bordered input-sm mb-2 w-full" placeholder="Ketik nama, telepon, atau alamat..." x-model="supplierSearch">
                                <div class="max-h-64 overflow-auto">
                                    <template x-if="filteredSuppliers.length === 0">
                                        <div class="py-3 text-center text-base-content/50">Supplier tidak ditemukan.</div>
                                    </template>
                                    <template x-for="item in filteredSuppliers" :key="item.id">
                                        <button type="button" class="block w-full rounded-lg p-2 text-left hover:bg-base-200" @click="pickSupplier(item)">
                                            <span class="block font-semibold" x-text="item.nama"></span>
                                            <small class="text-base-content/50" x-text="`${item.telp || '-'} · ${item.alamat || '-'}`"></small>
                                        </button>
                                    </template>
                                </div>
                            </div>
                            <template x-if="selectedSupplier">
                                <div class="mt-2 rounded-lg border border-base-300 bg-base-200/40 p-3">
                                    <div class="font-bold text-primary" x-text="selectedSupplier.nama"></div>
                                    <small class="text-base-content/50" x-text="`${selectedSupplier.telp || '-'} · ${selectedSupplier.alamat || '-'}`"></small>
                                </div>
                            </template>
                        </div>

                        <div class="form-control relative">
                            <label class="label"><span class="label-text font-semibold">2. Pilih LPB / BAP Supplier <span class="text-error">*</span></span></label>
                            <button type="button" class="select select-bordered text-left" :disabled="!supplierId" @click="receiptPickerOpen = !receiptPickerOpen">
                                <span x-text="receiptButtonLabel"></span>
                            </button>
                            <div x-show="receiptPickerOpen" x-cloak @click.outside="receiptPickerOpen = false"
                                class="absolute top-full z-30 mt-1 w-full rounded-box border border-base-300 bg-base-100 p-2 shadow-lg">
                                <input type="search" class="input input-bordered input-sm mb-2 w-full" placeholder="Cari nomor LPB, BAP, PO, atau tanggal..." x-model="receiptSearch">
                                <div class="max-h-72 overflow-auto">
                                    <template x-if="filteredReceipts.length === 0">
                                        <div class="py-3 text-center text-base-content/50">Tidak ada LPB/BAP yang cocok.</div>
                                    </template>
                                    <template x-for="item in filteredReceipts" :key="item.id">
                                        <label class="flex items-start gap-2 rounded-lg p-2 hover:bg-base-200">
                                            <input type="checkbox" class="checkbox checkbox-sm mt-1" :value="item.id" :checked="selectedLpbIds.includes(item.id)" @change="toggleReceipt(item.id)">
                                            <span class="flex-1">
                                                <span class="flex items-center justify-between gap-2">
                                                    <strong x-text="item.code"></strong>
                                                    <span class="badge badge-primary badge-outline" x-text="item.type"></span>
                                                </span>
                                                <small class="text-base-content/50" x-text="`Tanggal ${item.date} · PO ${item.po || '-'}`"></small>
                                            </span>
                                        </label>
                                    </template>
                                </div>
                            </div>
                            <span class="label-text-alt mt-1 text-base-content/50" x-text="receiptHelp"></span>
                        </div>

                        <div class="form-control">
                            <label class="label"><span class="label-text font-semibold">No. Invoice Supplier <span class="text-error">*</span></span></label>
                            <input type="text" x-model="form.no_invoice" class="input input-bordered" placeholder="Masukkan Nomor Invoice" required>
                        </div>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div class="form-control">
                                <label class="label"><span class="label-text font-semibold">Tanggal Invoice <span class="text-error">*</span></span></label>
                                <input type="date" x-model="form.tanggal" class="input input-bordered" required>
                            </div>
                            <div class="form-control">
                                <label class="label"><span class="label-text font-semibold">Deadline Pembayaran</span></label>
                                <input type="date" x-model="form.tgl_deadline_pembayaran" class="input input-bordered">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-4 border border-base-300 bg-base-100 p-4 shadow-sm">
                    <h6 class="mb-3 font-bold"><i class="fa-solid fa-boxes-stacked text-primary"></i> Preview Item LPB / BAP Terpilih</h6>
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th class="text-center">#</th>
                                    <th>Nama Bahan</th>
                                    <th class="text-center">Qty Diterima</th>
                                    <th class="text-end">Harga Satuan</th>
                                    <th class="text-end">Total Harga</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-if="previewItems.length === 0">
                                    <tr>
                                        <td colspan="5" class="py-3 text-center text-base-content/50">Pilih LPB atau BAP terlebih dahulu.</td>
                                    </tr>
                                </template>
                                <template x-for="(item, idx) in previewItems" :key="idx">
                                    <tr>
                                        <td class="text-center" x-text="idx + 1"></td>
                                        <td class="font-bold" x-text="item.nama_bahan"></td>
                                        <td class="text-center font-bold text-success" x-text="item.jumlah_barang_diterima"></td>
                                        <td class="text-end" x-text="formatRupiah(item.harga)"></td>
                                        <td class="text-end font-bold" x-text="formatRupiah(item.total_harga)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="card border border-base-300 bg-base-100 p-4 shadow-sm">
                        <label class="label"><span class="label-text font-semibold">Catatan / Note</span></label>
                        <textarea x-model="form.note" class="textarea textarea-bordered h-full" rows="5" placeholder="Catatan opsional untuk invoice ini..."></textarea>
                    </div>
                    <div class="card border border-base-300 bg-base-100 p-4 shadow-sm">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="font-semibold text-base-content/60">Sub Total:</span>
                            <span class="text-lg font-bold" x-text="'Rp ' + subTotal.toLocaleString('id-ID')"></span>
                        </div>
                        <div class="mb-2 flex items-center justify-between">
                            <label class="flex cursor-pointer items-center gap-2">
                                <input type="checkbox" x-model="form.is_ppn" class="checkbox">
                                <span class="font-semibold">Gunakan PPN (11%)</span>
                            </label>
                            <span class="font-semibold text-base-content/60" x-text="'Rp ' + ppnNominal.toLocaleString('id-ID')"></span>
                        </div>
                        <div x-show="form.is_ppn" x-cloak class="mb-2">
                            <label class="label"><span class="label-text font-semibold">No. Faktur Pajak (NSFP) <span class="text-error">*</span></span></label>
                            <input type="text" x-model="form.no_faktur_pajak" class="input input-bordered input-sm w-full" placeholder="17 digit angka" pattern="(\d{17}|\d{3}\.\d{3}-\d{2}\.\d{8})">
                            <span class="label-text-alt mt-1 text-base-content/50">Format: 17 digit angka (atau format lama 010.001-23.12345678) — wajib diisi bila PPN dipakai, syarat kredit PPN Masukan.</span>
                            <label class="label"><span class="label-text font-semibold">Tanggal Faktur Pajak</span></label>
                            <input type="date" x-model="form.tanggal_faktur_pajak" class="input input-bordered input-sm w-full">
                        </div>
                        <div class="mb-2 flex items-center gap-3">
                            <label class="w-24 font-semibold">Diskon:</label>
                            <input type="number" step="any" min="0" x-model.number="form.diskon" data-money-input class="input input-bordered input-sm flex-1 text-end">
                        </div>
                        <div class="mb-2 flex items-center gap-3">
                            <label class="w-24 font-semibold">Ongkir:</label>
                            <input type="number" step="any" min="0" x-model.number="form.ongkir" data-money-input class="input input-bordered input-sm flex-1 text-end">
                        </div>
                        <div class="mb-2 flex items-center gap-3">
                            <label class="w-24 font-semibold">PPN Impor:</label>
                            <input type="number" step="any" min="0" x-model.number="form.ppn_impor" data-money-input class="input input-bordered input-sm flex-1 text-end">
                        </div>
                        <div class="mb-2 flex items-center gap-3">
                            <label class="w-24 font-semibold">PPnBM:</label>
                            <input type="number" step="any" min="0" x-model.number="form.ppnbm" data-money-input class="input input-bordered input-sm flex-1 text-end">
                        </div>
                        <div class="mb-2">
                            <label class="label"><span class="label-text font-semibold">Mata Uang Asing (opsional)</span></label>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                <select x-model="form.mata_uang_asing" class="select select-bordered select-sm">
                                    <option value="">Tidak ada (IDR)</option>
                                    <option value="USD">USD</option>
                                    <option value="EUR">EUR</option>
                                    <option value="SGD">SGD</option>
                                    <option value="JPY">JPY</option>
                                    <option value="CNY">CNY</option>
                                </select>
                                <input type="number" step="any" min="0.0001" x-show="form.mata_uang_asing" x-cloak x-model.number="form.kurs" class="input input-bordered input-sm" placeholder="Kurs">
                                <input type="number" step="any" min="0.01" x-show="form.mata_uang_asing" x-cloak x-model.number="form.nilai_asing" class="input input-bordered input-sm" placeholder="Nilai asing">
                            </div>
                            <span class="label-text-alt mt-1 text-base-content/50">Catatan referensi nilai asli invoice supplier dalam mata uang asing; seluruh perhitungan sistem tetap dalam Rupiah. Selisih kurs saat pembayaran dicatat lewat kolom Selisih Pembayaran di form pembayaran.</span>
                        </div>
                        <div class="mb-2">
                            <label class="label"><span class="label-text font-semibold">Jenis PPh</span></label>
                            <select x-model="form.jenis_pph" @change="form.kode_objek_pajak = ''" class="select select-bordered select-sm w-full">
                                <option value="">Tidak ada</option>
                                <option value="PPH23">PPh 23</option>
                                <option value="PPH22">PPh 22</option>
                                <option value="PPH4A2">PPh 4(2) Final</option>
                            </select>
                            <span class="label-text-alt mt-1 text-base-content/50">PPh dicatat dan dipotong saat pembayaran, bukan saat invoice diterima.</span>
                        </div>
                        <div class="mb-2" x-show="form.jenis_pph" x-cloak>
                            <label class="label"><span class="label-text font-semibold">Kode Objek Pajak (opsional)</span></label>
                            <select x-model="form.kode_objek_pajak" class="select select-bordered select-sm w-full">
                                <option value="">Belum dipilih</option>
                                <template x-for="kop in kodeObjekTersaring" :key="kop.kode">
                                    <option :value="kop.kode" x-text="kop.kode + ' - ' + kop.deskripsi"></option>
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
                <button type="submit" class="btn btn-primary" :disabled="submitting">
                    <span x-show="submitting" class="loading loading-spinner loading-sm"></span>
                    <i class="fa-solid fa-floppy-disk" x-show="!submitting"></i> Simpan Invoice
                </button>
            </div>
        </form>
    </div>
</dialog>

<script>
    function invoiceCreateForm(config) {
        return {
            suppliers: config.suppliers,
            lpbs: config.lpbs,
            supplierId: '',
            supplierPickerOpen: false,
            supplierSearch: '',
            selectedLpbIds: [],
            receiptPickerOpen: false,
            receiptSearch: '',
            previewItems: [],
            subTotal: 0,
            submitting: false,
            form: {
                no_invoice: '', tanggal: new Date().toISOString().substring(0, 10), tgl_deadline_pembayaran: '',
                is_ppn: false, no_faktur_pajak: '', tanggal_faktur_pajak: '', diskon: 0, ongkir: 0, ppn_impor: 0, ppnbm: 0, jenis_pph: 'PPH23', kode_objek_pajak: '', note: '',
                mata_uang_asing: '', kurs: '', nilai_asing: '',
            },

            kodeObjek: @js($kodeObjek),
            get kodeObjekTersaring() {
                return this.kodeObjek.filter((item) => item.jenis_pph === this.form.jenis_pph);
            },
            get selectedSupplier() {
                return this.suppliers.find((item) => String(item.id) === String(this.supplierId)) || null;
            },
            get filteredSuppliers() {
                const term = this.supplierSearch.trim().toLowerCase();
                if (!term) return this.suppliers;
                return this.suppliers.filter((item) => `${item.nama} ${item.telp} ${item.alamat}`.toLowerCase().includes(term));
            },
            get availableReceipts() {
                return this.lpbs.filter((item) => String(item.supplier_id) === String(this.supplierId));
            },
            get filteredReceipts() {
                const term = this.receiptSearch.trim().toLowerCase();
                if (!term) return this.availableReceipts;
                return this.availableReceipts.filter((item) => `${item.code} ${item.type} ${item.date} ${item.po}`.toLowerCase().includes(term));
            },
            get receiptButtonLabel() {
                if (!this.supplierId) return 'Pilih supplier terlebih dahulu';
                if (this.selectedLpbIds.length === 0) return 'Pilih LPB / BAP (bisa lebih dari satu)';
                const codes = this.lpbs.filter((item) => this.selectedLpbIds.includes(item.id)).map((item) => item.code);
                return `${codes.length} dokumen dipilih: ${codes.join(', ')}`;
            },
            get receiptHelp() {
                return this.supplierId ? `${this.availableReceipts.length} dokumen LPB/BAP belum ditagih tersedia.` : 'Pilih supplier terlebih dahulu.';
            },
            get ppnNominal() {
                return this.form.is_ppn ? Math.round((this.subTotal * 11) / 100) : 0;
            },
            get grandTotal() {
                return (this.subTotal + this.ppnNominal + (Number(this.form.ongkir) || 0) + (Number(this.form.ppn_impor) || 0)) - (Number(this.form.diskon) || 0);
            },

            pickSupplier(item) {
                this.supplierId = item.id;
                this.supplierPickerOpen = false;
                this.selectedLpbIds = [];
                this.previewItems = [];
                this.subTotal = 0;
            },

            toggleReceipt(id) {
                if (this.selectedLpbIds.includes(id)) {
                    this.selectedLpbIds = this.selectedLpbIds.filter((item) => item !== id);
                } else {
                    this.selectedLpbIds = [...this.selectedLpbIds, id];
                }
                this.loadPreview();
            },

            async loadPreview() {
                if (this.selectedLpbIds.length === 0) {
                    this.previewItems = [];
                    this.subTotal = 0;
                    return;
                }
                try {
                    const codes = this.lpbs.filter((item) => this.selectedLpbIds.includes(item.id)).map((item) => item.code);
                    const results = await Promise.all(codes.map((code) =>
                        fetch(`{{ url('faktur-pembelian/lpb-detail') }}/${code}`, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).then((r) => r.json())
                    ));
                    let subTotal = 0;
                    const items = [];
                    results.forEach((res) => {
                        if (res.success) {
                            subTotal += Number(res.sub_total);
                            items.push(...res.items);
                        }
                    });
                    this.previewItems = items;
                    this.subTotal = subTotal;
                } catch (error) {
                    window.AppAlert.error('Gagal memuat detail LPB/BAP.');
                }
            },

            async submit() {
                if (!this.supplierId) { window.AppAlert.warning('Supplier wajib dipilih dari dropdown.'); return; }
                if (this.selectedLpbIds.length === 0) { window.AppAlert.warning('Pilih minimal satu LPB atau BAP dari supplier tersebut.'); return; }
                if (this.form.is_ppn && !/^(\d{17}|\d{3}\.\d{3}-\d{2}\.\d{8})$/.test((this.form.no_faktur_pajak || '').trim())) {
                    window.AppAlert.warning('Nomor Faktur Pajak wajib diisi dengan format yang benar saat PPN dipakai.');
                    return;
                }

                this.submitting = true;
                try {
                    const payload = { ...this.form, kode_supplier: this.supplierId, lpb_ids: this.selectedLpbIds, is_ppn: this.form.is_ppn ? 1 : 0 };
                    const response = await fetch('{{ route('faktur-pembelian.store') }}', {
                        method: 'POST',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload),
                    });
                    const data = await response.json().catch(() => ({}));
                    if (!response.ok) { window.AppAlert.ajaxError(data); return; }
                    window.AppAlert.auto(data);
                    this.$root.querySelector('form').dispatchEvent(new Event('wms:saved'));
                    window.dispatchEvent(new CustomEvent('wms:table-refresh'));
                    this.$root.closest('dialog').close();
                } catch (error) {
                    window.AppAlert.error('Gagal menyimpan invoice.');
                } finally {
                    this.submitting = false;
                }
            },
        };
    }
</script>
