@extends('layouts.app')

@section('content')
    <div class="content-page">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-2xl font-bold">Daftar Invoice LPB</h3>
                <p class="text-base-content/60">Kelola data tagihan dan pelunasan penerimaan barang</p>
            </div>
            @can('create', App\Models\FakturPembelian::class)
                <button type="button" class="btn btn-primary" onclick="openAjaxModal('{{ route('faktur-pembelian.create') }}')">
                    <i class="fa-solid fa-plus"></i> Buat Invoice LPB
                </button>
            @endcan
        </div>

        <div class="card border border-base-300 bg-base-100 shadow-sm"
            x-data="invoiceLpbList({
                paymentNumber: {{ Js::from($paymentNumber) }},
                focusId: '{{ request()->query('invoice', '') }}',
            })">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-base-300 p-4">
                <div role="tablist" class="tabs tabs-boxed">
                    <a role="tab" class="tab" :class="extraParams.payment_status === 'PENDING_APPROVAL' && 'tab-active'" @click="extraParams.payment_status = 'PENDING_APPROVAL'">
                        <i class="fa-solid fa-hourglass-half"></i>&nbsp;Menunggu Persetujuan
                    </a>
                    <a role="tab" class="tab" :class="extraParams.payment_status === 'UNPAID' && 'tab-active'" @click="extraParams.payment_status = 'UNPAID'">
                        <i class="fa-regular fa-clock"></i>&nbsp;Belum Lunas
                    </a>
                    <a role="tab" class="tab" :class="extraParams.payment_status === 'PARTIALLY_PAID' && 'tab-active'" @click="extraParams.payment_status = 'PARTIALLY_PAID'">
                        <i class="fa-solid fa-circle-half-stroke"></i>&nbsp;Dibayar Sebagian
                    </a>
                    <a role="tab" class="tab" :class="extraParams.payment_status === 'PAID' && 'tab-active'" @click="extraParams.payment_status = 'PAID'">
                        <i class="fa-solid fa-circle-check"></i>&nbsp;Lunas
                    </a>
                </div>
                <label class="input input-bordered flex w-full max-w-xs items-center gap-2">
                    <i class="fa-solid fa-magnifying-glass text-base-content/40"></i>
                    <input type="search" class="grow" placeholder="Cari invoice..." x-model="search">
                </label>
                <a :href="buildReportUrl()" target="_blank" rel="noopener" class="btn btn-error btn-sm">
                    <i class="fa-solid fa-file-pdf"></i> PDF
                </a>
                <a :href="buildReportUrl('excel')" target="_blank" rel="noopener" class="btn btn-success btn-sm">
                    <i class="fa-solid fa-file-excel"></i> Excel
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th class="cursor-pointer select-none" @click="sortBy(0)">No. Invoice</th>
                            <th class="cursor-pointer select-none" @click="sortBy(1)">Tanggal</th>
                            <th class="cursor-pointer select-none" @click="sortBy(2)">Supplier</th>
                            <th class="cursor-pointer select-none" @click="sortBy(3)">Deadline</th>
                            <th class="cursor-pointer select-none text-end" @click="sortBy(4)">Grand Total</th>
                            <th class="cursor-pointer select-none text-end" @click="sortBy(5)">Sisa Tagihan</th>
                            <th class="cursor-pointer select-none text-center" @click="sortBy(6)">Status</th>
                            <th class="text-center">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-if="loading">
                            <tr>
                                <td colspan="8" class="py-6 text-center text-base-content/50">
                                    <span class="loading loading-spinner loading-sm"></span> Memuat data...
                                </td>
                            </tr>
                        </template>
                        <template x-if="!loading && rows.length === 0">
                            <tr>
                                <td colspan="8" class="py-6 text-center text-base-content/50">Data tidak ditemukan</td>
                            </tr>
                        </template>
                        <template x-for="row in rows" :key="row.id">
                            <tr>
                                <td class="font-bold text-primary" x-text="row.no_invoice"></td>
                                <td x-text="row.tanggal"></td>
                                <td x-text="row.supplier_nama"></td>
                                <td x-text="row.tgl_deadline_pembayaran || '-'"></td>
                                <td class="text-end font-bold" x-text="formatRupiah(row.grand_total)"></td>
                                <td class="text-end font-bold text-error" x-text="formatRupiah(row.sisa_tagihan)"></td>
                                <td class="text-center">
                                    <span class="badge"
                                        :class="{ 'badge-success': row.status === 'PAID', 'badge-warning': row.status === 'PARTIALLY_PAID', 'badge-ghost': row.status === 'UNPAID', 'badge-info': row.status === 'PENDING_APPROVAL' }"
                                        x-text="row.status === 'PAID' ? 'Lunas' : (row.status === 'PARTIALLY_PAID' ? 'Dibayar Sebagian' : (row.status === 'PENDING_APPROVAL' ? 'Menunggu Persetujuan' : 'Belum Dibayar'))"></span>
                                </td>
                                <td class="text-center">
                                    <div class="flex items-center justify-center gap-1">
                                        <button type="button" class="btn btn-outline btn-info btn-sm" title="Detail" @click="openShow(row.id)">
                                            <i class="fa-solid fa-eye"></i>
                                        </button>
                                        <button type="button" x-show="row.can_approve" class="btn btn-outline btn-success btn-sm" title="Setujui Invoice"
                                            @click="approveInvoice(row.id)">
                                            <i class="fa-solid fa-check"></i>
                                        </button>
                                        <button type="button" x-show="row.can_update" class="btn btn-outline btn-warning btn-sm" title="Edit Invoice"
                                            @click="openAjaxModal(`{{ url('faktur-pembelian') }}/${row.id}/edit`)">
                                            <i class="fa-solid fa-pen-to-square"></i>
                                        </button>
                                        <button type="button" x-show="row.can_delete" class="btn btn-outline btn-error btn-sm" title="Hapus"
                                            @click="deleteInvoice(row.id)">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 p-4 text-sm">
                <span class="text-base-content/60">
                    Menampilkan <span x-text="rangeStart"></span>–<span x-text="rangeEnd"></span> dari
                    <span x-text="recordsFiltered"></span> data
                </span>
                <div class="join">
                    <button type="button" class="join-item btn btn-sm" :disabled="currentPage === 0" @click="goToPage(currentPage - 1)">
                        <i class="fa-solid fa-chevron-left"></i>
                    </button>
                    <button type="button" class="join-item btn btn-sm btn-disabled" x-text="`${currentPage + 1} / ${pageCount}`"></button>
                    <button type="button" class="join-item btn btn-sm" :disabled="currentPage >= pageCount - 1" @click="goToPage(currentPage + 1)">
                        <i class="fa-solid fa-chevron-right"></i>
                    </button>
                </div>
            </div>

            <dialog x-ref="showDialog" class="modal">
                <div class="modal-box max-w-5xl p-0" x-show="invoice">
                    <div class="flex items-center justify-between bg-info px-6 py-4 text-info-content">
                        <h3 class="text-lg font-bold"><i class="fa-solid fa-file-invoice-dollar"></i> Invoice: <span x-text="invoice?.no_invoice"></span></h3>
                    </div>
                    <div class="max-h-[75vh] overflow-y-auto p-6" x-show="invoice">
                        <div class="card border border-base-300 bg-base-100 p-4 shadow-sm">
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                <div><p class="text-sm text-base-content/50">Supplier</p><p class="text-lg font-bold" x-text="invoice?.supplier?.nama || '-'"></p></div>
                                <div><p class="text-sm text-base-content/50">Tanggal Invoice</p><p class="text-lg font-bold" x-text="invoice?.tanggal"></p></div>
                                <div><p class="text-sm text-base-content/50">Deadline</p><p class="text-lg font-bold" x-text="invoice?.tgl_deadline_pembayaran || '-'"></p></div>
                            </div>
                            <div class="divider my-2"></div>
                            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                                <div><p class="text-sm text-base-content/50">Sub Total</p><p class="font-bold" x-text="formatRupiah(invoice?.sub_total || 0)"></p></div>
                                <div><p class="text-sm text-base-content/50" x-text="`PPN (${Number(invoice?.tarif_ppn || 0).toLocaleString('id-ID')}%)`"></p><p class="font-bold" x-text="formatRupiah(invoice?.ppn || 0)"></p></div>
                                <div><p class="text-sm text-base-content/50">Grand Total</p><p class="text-lg font-bold text-primary" x-text="formatRupiah(invoice?.grand_total || 0)"></p></div>
                                <div><p class="text-sm text-base-content/50">Sisa Tagihan</p><p class="text-lg font-bold text-error" x-text="formatRupiah(invoice?.sisa_tagihan || 0)"></p></div>
                            </div>
                            <template x-if="invoice?.no_faktur_pajak">
                                <div class="mt-2"><p class="text-sm text-base-content/50">No. Faktur Pajak</p><p class="font-bold" x-text="invoice?.no_faktur_pajak"></p></div>
                            </template>
                        </div>

                        <template x-if="invoice?.status === 'PENDING_APPROVAL'">
                            <div class="alert alert-info mb-3 text-sm">
                                <i class="fa-solid fa-hourglass-half"></i>
                                <span>Invoice ini menunggu persetujuan Accounting Manager sebelum dicatat ke Jurnal COA dan dapat dibayar.</span>
                                <button type="button" x-show="invoice?.can_approve" class="btn btn-success btn-sm" @click="approveInvoice(invoice.id)">
                                    <i class="fa-solid fa-check"></i> Setujui
                                </button>
                            </div>
                        </template>

                        <div class="mb-3 mt-4 flex items-center justify-between">
                            <h6 class="font-bold"><i class="fa-solid fa-receipt text-info"></i> Riwayat Pembayaran</h6>
                            <button type="button" x-show="invoice?.can_pay" class="btn btn-success btn-sm" @click="openPaymentPanel()">
                                <i class="fa-solid fa-plus"></i> Tambah Pembayaran
                            </button>
                        </div>
                        <div class="overflow-x-auto rounded-lg border border-base-300 bg-base-100">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th class="text-center">#</th>
                                        <th>No Pembayaran</th>
                                        <th>Tgl Bayar</th>
                                        <th>Metode</th>
                                        <th>Akun Kas/Bank (COA)</th>
                                        <th class="text-end">Jml Bayar</th>
                                        <th class="text-end" x-text="invoice?.jenis_pph ? pphLabel(invoice.jenis_pph) : 'PPh'"></th>
                                        <th class="text-end">Selisih</th>
                                        <th class="text-end">Total Pengurang</th>
                                        <th>User Finance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template x-if="!invoice?.payments || invoice.payments.length === 0">
                                        <tr>
                                            <td colspan="10" class="py-3 text-center text-base-content/50">Belum ada riwayat pembayaran.</td>
                                        </tr>
                                    </template>
                                    <template x-for="(item, i) in (invoice?.payments || [])" :key="item.id">
                                        <tr>
                                            <td class="text-center" x-text="i + 1"></td>
                                            <td class="font-semibold" x-text="item.payment_number ?? '-'"></td>
                                            <td x-text="item.tanggal_pembayaran"></td>
                                            <td class="font-bold" x-text="item.metode_pembayaran"></td>
                                            <td class="font-bold text-info" x-text="item.coa_kas_bank ? `${item.coa_kas_bank.kode_akun} - ${item.coa_kas_bank.nama_akun}` : '-'"></td>
                                            <td class="text-end" x-text="formatRupiah(item.jumlah_pembayaran)"></td>
                                            <td class="text-end" x-text="formatRupiah(item.potongan_pph)"></td>
                                            <td class="text-end">
                                                <span x-text="formatRupiah(item.selisih_bayar)"></span>
                                                <small class="block text-base-content/50" x-text="item.jenis_selisih ? item.jenis_selisih.replaceAll('_', ' ') : ''"></small>
                                            </td>
                                            <td class="text-end font-bold text-success" x-text="formatRupiah(item.total_transaksi_pengurang_hutang)"></td>
                                            <td x-text="item.user_finance ? item.user_finance.name : '-'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>

                        <div x-show="paymentPanelOpen" x-cloak class="mt-4 rounded-lg border border-base-300 bg-base-200/40 p-4">
                            <div class="mb-3 flex items-center justify-between border-b border-base-300 pb-3">
                                <h5 class="font-bold"><i class="fa-solid fa-money-bill-wave"></i> Catat Pembayaran</h5>
                                <button type="button" class="btn btn-sm btn-outline" @click="paymentPanelOpen = false">
                                    <i class="fa-solid fa-xmark"></i> Tutup
                                </button>
                            </div>
                            <form @submit.prevent="submitPayment()" class="flex flex-col gap-3">
                                <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Nomor Pembayaran</span></label>
                                        <input type="text" class="input input-bordered bg-base-200" :value="paymentNumber" readonly>
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Tanggal Pembayaran <span class="text-error">*</span></span></label>
                                        <input type="date" x-model="payment.tanggal_pembayaran" class="input input-bordered" required>
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Metode Pembayaran <span class="text-error">*</span></span></label>
                                        <input type="text" x-model="payment.metode_pembayaran" class="input input-bordered" placeholder="Contoh: Transfer BCA" required>
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Akun Sumber (Kas/Bank) <span class="text-error">*</span></span></label>
                                        <select x-model="payment.coa_kas_bank_id" class="select select-bordered" required>
                                            <option value="">-- Pilih Akun Kas / Bank --</option>
                                            <template x-for="coa in coaKasBankOptions" :key="coa.id">
                                                <option :value="coa.id" x-text="`${coa.kode_akun} - ${coa.nama_akun}`"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Jumlah Pembayaran</span></label>
                                        <input type="number" step="any" min="0" x-model.number="payment.jumlah_pembayaran" data-money-input class="input input-bordered">
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase" x-text="invoice?.jenis_pph ? `Potongan ${pphLabel(invoice.jenis_pph)}` : 'Potongan PPh'"></span></label>
                                        <input type="number" step="any" min="0" x-model.number="payment.potongan_pph" data-money-input class="input input-bordered" :disabled="!invoice?.jenis_pph">
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Materai Tambahan</span></label>
                                        <label class="flex cursor-pointer items-center gap-2 rounded-lg border border-base-300 bg-base-100 p-3">
                                            <input type="checkbox" x-model="payment.potongan_materai" class="checkbox">
                                            <span><strong class="block">Gunakan materai</strong><small class="text-base-content/50">Biaya tetap Rp10.000</small></span>
                                        </label>
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Biaya Transfer Bank</span></label>
                                        <input type="number" step="any" min="0" x-model.number="payment.biaya_transfer_bank" data-money-input class="input input-bordered">
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Nominal Selisih / Kelebihan Bayar</span></label>
                                        <input type="number" step="any" min="0" x-model.number="payment.selisih_bayar" data-money-input class="input input-bordered">
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Jenis Selisih</span></label>
                                        <select x-model="payment.jenis_selisih" class="select select-bordered">
                                            <option value="">Tidak ada selisih</option>
                                            <option value="PENDAPATAN_SELISIH">Pendapatan selisih</option>
                                            <option value="BEBAN_SELISIH">Beban selisih</option>
                                            <option value="UANG_MUKA_SUPPLIER">Uang muka supplier</option>
                                        </select>
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Akun Selisih / Uang Muka</span></label>
                                        <select x-model="payment.coa_selisih_id" class="select select-bordered">
                                            <option value="">-- Pilih Akun Selisih / Uang Muka --</option>
                                            <template x-for="coa in coaPostableOptions" :key="coa.id">
                                                <option :value="coa.id" x-text="`${coa.kode_akun} - ${coa.nama_akun}`"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Pakai Uang Muka Supplier</span></label>
                                        <select x-model="payment.uang_muka_sumber_payment_id" @change="onAdvanceChange()" class="select select-bordered">
                                            <option value="">Tidak memakai uang muka</option>
                                            <template x-for="advance in advanceOptions" :key="advance.id">
                                                <option :value="advance.id" :data-sisa="advance.sisa" x-text="`${advance.payment_number} (asal ${advance.no_invoice_asal}) — sisa Rp ${Number(advance.sisa).toLocaleString('id-ID')}`"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Nominal Uang Muka Dipakai</span></label>
                                        <input type="number" step="any" min="0" x-model.number="payment.uang_muka_dipakai" class="input input-bordered" :disabled="!payment.uang_muka_sumber_payment_id">
                                    </div>
                                    <div class="form-control">
                                        <label class="label"><span class="label-text font-semibold text-xs uppercase">Keterangan</span></label>
                                        <input type="text" x-model="payment.keterangan" class="input input-bordered" placeholder="Catatan tambahan...">
                                    </div>
                                </div>

                                <div class="grid grid-cols-2 gap-3 rounded-lg bg-base-100 p-4 md:grid-cols-4">
                                    <div><small class="text-base-content/50">Kas keluar</small><strong class="block" x-text="formatRupiah(draft.cashOut)"></strong><span class="text-xs text-base-content/40">Bayar + materai + transfer</span></div>
                                    <div><small class="text-base-content/50">Pengurang hutang</small><strong class="block" x-text="formatRupiah(draft.reduction)"></strong><span class="text-xs text-base-content/40">Termasuk PPh dan selisih</span></div>
                                    <div><small class="text-base-content/50">Biaya tambahan</small><strong class="block" x-text="formatRupiah(draft.extraCost)"></strong><span class="text-xs text-base-content/40">Materai + transfer</span></div>
                                    <div>
                                        <small class="text-base-content/50">Estimasi sisa tagihan</small>
                                        <strong class="block" :class="draft.invalid ? 'text-error' : (draft.estimatedRemaining <= 0 ? 'text-success' : '')" x-text="formatRupiah(draft.estimatedRemaining)"></strong>
                                        <span class="text-xs text-base-content/40" x-text="draft.invalid ? 'Periksa nominal — pengurang hutang tidak valid' : (draft.estimatedRemaining <= 0 ? 'Invoice akan menjadi lunas' : 'Invoice masih memiliki sisa')"></span>
                                    </div>
                                </div>

                                <div class="flex justify-end gap-2">
                                    <button type="button" class="btn btn-ghost border border-base-300" @click="paymentPanelOpen = false">Batal</button>
                                    <button type="submit" class="btn btn-success" :disabled="paymentSubmitting">
                                        <span x-show="paymentSubmitting" class="loading loading-spinner loading-sm"></span>
                                        <i class="fa-solid fa-floppy-disk" x-show="!paymentSubmitting"></i> Simpan Pembayaran
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="flex justify-end border-t border-base-300 bg-base-100 px-6 py-4">
                        <button type="button" class="btn btn-ghost border border-base-300" @click="$refs.showDialog.close()">Tutup</button>
                    </div>
                </div>
                <div class="modal-backdrop" @click="$refs.showDialog.close()"></div>
            </dialog>
        </div>
    </div>

    <div id="modal-container"></div>

    <script>
        function invoiceLpbList(config) {
            return {
                ...wmsDataTable({
                    url: '{{ route('faktur-pembelian.index') }}',
                    reportUrl: '{{ route('faktur-pembelian.report.pdf') }}',
                    extraParams: { payment_status: 'UNPAID', focus: config.focusId || '' },
                    columns: [
                        { data: 'no_invoice' }, { data: 'tanggal' }, { data: 'supplier_nama' }, { data: 'tgl_deadline_pembayaran' },
                        { data: 'grand_total' }, { data: 'sisa_tagihan' }, { data: 'status_pembayaran' }, { data: 'aksi', orderable: false, searchable: false },
                    ],
                }),
                paymentNumber: config.paymentNumber,
                invoice: null,
                paymentPanelOpen: false,
                paymentSubmitting: false,
                coaKasBankOptions: [],
                coaPostableOptions: [],
                advanceOptions: [],
                payment: {},

                async init() {
                    this.payment = this.defaultPayment();
                    await this.fetchData();
                    this.$watch('search', () => { this.start = 0; this.debouncedFetch(); });
                    this.$watch('extraParams', () => { this.start = 0; this.fetchData(); }, { deep: true });
                    this.$watch('length', () => { this.start = 0; this.fetchData(); });
                    window.addEventListener('wms:table-refresh', () => this.fetchData());
                    if (config.focusId && this.rows.length > 0) {
                        this.openShow(this.rows[0].id);
                    }
                },

                defaultPayment() {
                    return {
                        tanggal_pembayaran: new Date().toISOString().substring(0, 10), metode_pembayaran: '',
                        coa_kas_bank_id: '', jumlah_pembayaran: 0, potongan_pph: 0, potongan_materai: false,
                        biaya_transfer_bank: 0, selisih_bayar: 0, jenis_selisih: '', coa_selisih_id: '',
                        uang_muka_sumber_payment_id: '', uang_muka_dipakai: 0, keterangan: '',
                    };
                },

                pphLabel(type) {
                    return { PPH23: 'PPh 23', PPH22: 'PPh 22', PPH4A2: 'PPh 4(2) Final' }[type] || type || '-';
                },

                get draft() {
                    const remaining = Number(this.invoice?.sisa_tagihan || 0);
                    const payment = Number(this.payment.jumlah_pembayaran) || 0;
                    const pph = Number(this.payment.potongan_pph) || 0;
                    const stamp = this.payment.potongan_materai ? 10000 : 0;
                    const transfer = Number(this.payment.biaya_transfer_bank) || 0;
                    const difference = Number(this.payment.selisih_bayar) || 0;
                    const type = this.payment.jenis_selisih;
                    const advanceUsed = this.payment.uang_muka_sumber_payment_id ? (Number(this.payment.uang_muka_dipakai) || 0) : 0;
                    const cashAndTax = payment + pph;
                    const remainingAfterAdvance = Math.max(0, remaining - advanceUsed);

                    let cashReduction = cashAndTax;
                    if (type === 'PENDAPATAN_SELISIH') cashReduction += difference;
                    if (type === 'BEBAN_SELISIH') cashReduction -= difference;
                    if (type === 'UANG_MUKA_SUPPLIER') cashReduction = Math.min(remainingAfterAdvance, cashAndTax);
                    const reduction = Math.max(0, cashReduction) + advanceUsed;

                    const cashOut = payment + stamp + transfer;
                    const extraCost = stamp + transfer;
                    const estimatedRemaining = Math.max(0, remaining - reduction);
                    const invalid = reduction <= 0 || reduction > remaining + 0.01;

                    return { cashOut, reduction, extraCost, estimatedRemaining, invalid };
                },

                async openShow(id) {
                    try {
                        const response = await fetch(`{{ url('faktur-pembelian') }}/${id}`, {
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        });
                        const res = await response.json();
                        if (!res.success) return;
                        this.invoice = res.data;
                        this.paymentPanelOpen = false;
                        this.$refs.showDialog.showModal();
                    } catch (error) {
                        window.AppAlert.error('Gagal mengambil data detail invoice.');
                    }
                },

                async openPaymentPanel() {
                    this.payment = this.defaultPayment();
                    this.payment.jumlah_pembayaran = Number(this.invoice?.sisa_tagihan || 0);
                    try {
                        const [coaRes, advanceRes] = await Promise.all([
                            fetch('{{ url('bagan-akun/kas-bank') }}', { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).then((r) => r.json()),
                            fetch(`{{ url('pembayaran-faktur/available-advances') }}/${this.invoice.kode_supplier}`, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } }).then((r) => r.json()),
                        ]);
                        this.coaKasBankOptions = coaRes.data || [];
                        this.coaPostableOptions = coaRes.postable || [];
                        this.advanceOptions = advanceRes.data || [];
                        this.paymentPanelOpen = true;
                    } catch (error) {
                        window.AppAlert.error('Gagal mengambil data akun Kas/Bank COA atau uang muka supplier.');
                    }
                },

                onAdvanceChange() {
                    const advance = this.advanceOptions.find((item) => String(item.id) === String(this.payment.uang_muka_sumber_payment_id));
                    this.payment.uang_muka_dipakai = advance ? Number(advance.sisa) : 0;
                },

                async submitPayment() {
                    this.paymentSubmitting = true;
                    try {
                        const payload = { ...this.payment, invoice_lpb_id: this.invoice.id, potongan_materai: this.payment.potongan_materai ? 10000 : 0 };
                        const response = await fetch('{{ route('pembayaran-faktur.store') }}', {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload),
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) { window.AppAlert.ajaxError(data); return; }
                        window.AppAlert.auto(data);
                        if (data.next_document_number) this.paymentNumber = data.next_document_number;
                        this.paymentPanelOpen = false;
                        this.$refs.showDialog.close();
                        this.fetchData();
                    } catch (error) {
                        window.AppAlert.error('Gagal menyimpan pembayaran.');
                    } finally {
                        this.paymentSubmitting = false;
                    }
                },

                async approveInvoice(id) {
                    const result = await window.AppAlert.confirm('Setujui invoice ini? Setelah disetujui, invoice akan dicatat ke Jurnal COA dan tidak dapat diubah lagi.');
                    if (!result.isConfirmed) return;
                    try {
                        const response = await fetch(`{{ url('faktur-pembelian') }}/${id}/approve`, {
                            method: 'POST',
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) { window.AppAlert.ajaxError(data); return; }
                        window.AppAlert.auto(data);
                        this.fetchData();
                        if (this.invoice?.id === id) this.openShow(id);
                    } catch (error) {
                        window.AppAlert.error('Gagal menyetujui invoice.');
                    }
                },

                async deleteInvoice(id) {
                    const result = await window.AppAlert.confirm('Hapus invoice ini? Invoice yang sudah dibayar tidak dapat dihapus.');
                    if (!result.isConfirmed) return;
                    try {
                        const response = await fetch(`{{ url('faktur-pembelian') }}/${id}`, {
                            method: 'DELETE',
                            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        });
                        const data = await response.json().catch(() => ({}));
                        if (!response.ok) { window.AppAlert.ajaxError(data); return; }
                        window.AppAlert.auto(data);
                        this.fetchData();
                    } catch (error) {
                        window.AppAlert.error('Gagal menghapus data.');
                    }
                },
            };
        }
    </script>
@endsection
