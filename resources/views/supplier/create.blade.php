<dialog id="supplierCreateModal" class="modal">
    <div class="modal-box max-w-2xl p-0">
        <div class="flex items-center gap-3 bg-primary px-6 py-4 text-primary-content">
            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-content/20">
                <i class="fa-solid fa-truck-field"></i>
            </div>
            <div>
                <h3 class="text-lg font-bold">Tambah Supplier Baru</h3>
                <p class="text-sm text-primary-content/80">Isi formulir di bawah ini untuk menambahkan data vendor baru</p>
            </div>
        </div>
        <form action="{{ route('supplier.store') }}" method="POST" class="flex flex-col">
            @csrf
            <div class="grid grid-cols-1 gap-4 p-6 sm:grid-cols-2">
                <div class="form-control">
                    <label class="label"><span class="label-text font-semibold">Nama Supplier <span class="text-error">*</span></span></label>
                    <div class="join">
                        <span class="join-item btn btn-disabled btn-outline"><i class="fa-solid fa-building"></i></span>
                        <input type="text" name="nama" class="input input-bordered join-item flex-1"
                            placeholder="Masukkan nama supplier..." required>
                    </div>
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text font-semibold">UP (Contact Person) <span class="text-error">*</span></span></label>
                    <div class="join">
                        <span class="join-item btn btn-disabled btn-outline"><i class="fa-solid fa-user"></i></span>
                        <input type="text" name="up" class="input input-bordered join-item flex-1"
                            placeholder="Nama penanggung jawab..." required>
                    </div>
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text font-semibold">No. Telepon <span class="text-error">*</span></span></label>
                    <div class="join">
                        <span class="join-item btn btn-disabled btn-outline"><i class="fa-solid fa-phone"></i></span>
                        <input type="text" name="telp" class="input input-bordered join-item flex-1"
                            placeholder="Nomor telepon..." required>
                    </div>
                </div>

                @include('partials.identitas-pajak', ['model' => null])

                <div class="form-control">
                    <label class="label"><span class="label-text font-semibold">Sistem Pembayaran <span class="text-error">*</span></span></label>
                    <div class="join">
                        <span class="join-item btn btn-disabled btn-outline"><i class="fa-solid fa-credit-card"></i></span>
                        <input type="text" name="pembayaran" class="input input-bordered join-item flex-1"
                            placeholder="Contoh: Transfer / COD / Tempo 30 Hari..." required>
                    </div>
                </div>

                <div class="form-control">
                    <label class="label"><span class="label-text font-semibold">Alamat <span class="text-error">*</span></span></label>
                    <div class="join">
                        <span class="join-item btn btn-disabled btn-outline"><i class="fa-solid fa-location-dot"></i></span>
                        <textarea name="alamat" class="textarea textarea-bordered join-item flex-1" rows="1"
                            placeholder="Masukkan alamat lengkap..." required></textarea>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-base-300 bg-base-100 px-6 py-4">
                <button type="button" class="btn btn-ghost" onclick="closeAjaxModal(this)">Batal</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Simpan Supplier
                </button>
            </div>
        </form>
    </div>
</dialog>
