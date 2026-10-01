@extends('layouts.app')

@section('content')
    <div class="content-page" x-data="{ baris: [{ bahan_id: '', jumlah: 1, harga_satuan: 0, satuan: '' }] }">
        <h3 class="mb-4 text-2xl font-bold">Pesanan Penjualan Baru</h3>
        @if (session('success'))
            <div class="alert alert-success mb-4"><i class="fa-solid fa-circle-check"></i><span>{{ session('success') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error mb-4">
                <i class="fa-solid fa-circle-exclamation"></i>
                <ul class="list-inside list-disc">@foreach ($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('pesanan-penjualan.store') }}" class="card border border-base-300 bg-base-100 p-4 shadow-sm">
            @csrf

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div class="form-control">
                    <label class="label"><span class="label-text">Tanggal</span></label>
                    <input type="date" name="tanggal" value="{{ old('tanggal', today()->format('Y-m-d')) }}" required class="input input-bordered input-sm">
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Pelanggan</span></label>
                    <select name="pelanggan_id" data-app-picker required class="select select-bordered select-sm">
                        <option value="">Pilih pelanggan</option>
                        @foreach ($pelanggan as $item)
                            <option value="{{ $item->id }}" @selected(old('pelanggan_id') == $item->id)>{{ $item->kode }} &middot; {{ $item->nama }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Gudang Pengirim</span></label>
                    <select name="gudang_id" required class="select select-bordered select-sm">
                        <option value="">Pilih gudang</option>
                        @foreach ($gudang as $item)
                            <option value="{{ $item->id }}" @selected(old('gudang_id') == $item->id)>{{ $item->nama }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Sales</span></label>
                    <select name="sales_user_id" data-app-picker class="select select-bordered select-sm">
                        <option value="">{{ auth()->user()->name }} (pembuat)</option>
                        @foreach ($sales as $item)
                            <option value="{{ $item->id }}" @selected(old('sales_user_id') == $item->id)>{{ $item->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Nomor PO Pelanggan</span></label>
                    <input type="text" name="nomor_po_pelanggan" value="{{ old('nomor_po_pelanggan') }}" class="input input-bordered input-sm">
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Tarif PPN (%)</span></label>
                    <input type="number" step="0.01" name="tarif_ppn" value="{{ old('tarif_ppn', 11) }}" class="input input-bordered input-sm">
                </div>
                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="hidden" name="is_ppn" value="0">
                        <input type="checkbox" name="is_ppn" value="1" @checked(old('is_ppn', true)) class="checkbox checkbox-sm">
                        <span class="label-text">Kena PPN</span>
                    </label>
                </div>
                @can('tembusPlafonKredit')
                    <div class="form-control md:col-span-3">
                        <label class="label">
                            <span class="label-text">Alasan menembus plafon kredit</span>
                            <span class="label-text-alt text-base-content/60">hanya diisi bila pesanan melewati plafon pelanggan</span>
                        </label>
                        <input type="text" name="alasan_plafon" value="{{ old('alasan_plafon') }}" maxlength="500"
                            class="input input-bordered input-sm" placeholder="Contoh: disetujui direksi, pelanggan melunasi minggu ini">
                    </div>
                @endcan
            </div>

            <div class="mt-4 overflow-x-auto">
                <table class="table table-sm">
                    <thead><tr><th>Bahan</th><th class="w-32">Jumlah</th><th class="w-40">Harga Satuan</th><th class="w-28">Satuan</th><th class="w-12"></th></tr></thead>
                    <tbody>
                        <template x-for="(row, i) in baris" :key="i">
                            <tr>
                                <td>
                                    <select :name="`details[${i}][bahan_id]`" x-model="row.bahan_id" required class="select select-bordered select-sm w-full">
                                        <option value="">Pilih bahan</option>
                                        @foreach ($bahan as $item)
                                            <option value="{{ $item->id }}">{{ $item->nama }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="number" step="0.000001" min="0.000001" :name="`details[${i}][jumlah]`" x-model="row.jumlah" required class="input input-bordered input-sm w-full"></td>
                                <td><input type="number" step="0.01" min="0" :name="`details[${i}][harga_satuan]`" x-model="row.harga_satuan" required class="input input-bordered input-sm w-full"></td>
                                <td><input type="text" :name="`details[${i}][satuan]`" x-model="row.satuan" class="input input-bordered input-sm w-full"></td>
                                <td><button type="button" class="btn btn-ghost btn-xs" @click="baris.length > 1 && baris.splice(i, 1)">&times;</button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex gap-2">
                <button type="button" class="btn btn-ghost btn-sm" @click="baris.push({ bahan_id: '', jumlah: 1, harga_satuan: 0, satuan: '' })">
                    <i class="fa-solid fa-plus"></i> Tambah Baris
                </button>
                <button type="submit" class="btn btn-primary btn-sm">Simpan Pesanan</button>
                <a href="{{ route('pesanan-penjualan.index') }}" class="btn btn-ghost btn-sm">Batal</a>
            </div>
        </form>
    </div>
@endsection
