@extends('layouts.app')

@section('content')
    <div class="content-page" x-data="{ baris: [{ bahan_id: '', jumlah: '', satuan: '' }] }">
        <div class="mb-4">
            <h3 class="text-2xl font-bold">BOM (Bill of Material)</h3>
            <p class="text-base-content/60">
                Daftar komponen standar per bahan hasil. Dipakai sebagai pembanding pemakaian aktual pada perintah kerja.
            </p>
        </div>

        @if (session('success'))
            <div class="alert alert-success mb-4"><i class="fa-solid fa-circle-check"></i><span>{{ session('success') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error mb-4">
                <i class="fa-solid fa-circle-exclamation"></i>
                <ul class="list-inside list-disc">@foreach ($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach</ul>
            </div>
        @endif

        @can('create', App\Models\Bom::class)
            <div class="card mb-4 border border-base-300 bg-base-100 shadow-sm">
                <div class="border-b border-base-300 p-4">
                    <h4 class="font-semibold">BOM Baru</h4>
                </div>
                <form method="POST" action="{{ route('bom.store') }}" class="p-4">
                    @csrf
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div class="form-control">
                            <label class="label"><span class="label-text">Kode</span></label>
                            <input type="text" name="kode" value="{{ old('kode') }}" required class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Nama</span></label>
                            <input type="text" name="nama" value="{{ old('nama') }}" required class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Bahan Hasil</span></label>
                            <select name="bahan_id" data-app-picker required class="select select-bordered select-sm">
                                <option value="">Pilih bahan hasil</option>
                                @foreach ($bahan as $item)
                                    <option value="{{ $item->id }}" @selected(old('bahan_id') == $item->id)>{{ $item->nama }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Versi</span></label>
                            <input type="text" name="versi" value="{{ old('versi', '1') }}" class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Jumlah Hasil per Set</span></label>
                            <input type="number" step="0.000001" name="jumlah_hasil" value="{{ old('jumlah_hasil', 1) }}" class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Catatan</span></label>
                            <input type="text" name="catatan" value="{{ old('catatan') }}" class="input input-bordered input-sm">
                        </div>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr><th>Komponen</th><th class="w-40">Jumlah</th><th class="w-32">Satuan</th><th class="w-16"></th></tr>
                            </thead>
                            <tbody>
                                <template x-for="(item, index) in baris" :key="index">
                                    <tr>
                                        <td>
                                            <select :name="`details[${index}][bahan_id]`" required class="select select-bordered select-sm w-full">
                                                <option value="">Pilih komponen</option>
                                                @foreach ($bahan as $item)
                                                    <option value="{{ $item->id }}">{{ $item->nama }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <input type="number" step="0.000001" :name="`details[${index}][jumlah]`" required
                                                class="input input-bordered input-sm w-full">
                                        </td>
                                        <td>
                                            <input type="text" :name="`details[${index}][satuan]`" class="input input-bordered input-sm w-full">
                                        </td>
                                        <td>
                                            <button type="button" class="btn btn-ghost btn-xs text-error"
                                                x-show="baris.length > 1" @click="baris.splice(index, 1)">Hapus</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline btn-sm"
                            @click="baris.push({ bahan_id: '', jumlah: '', satuan: '' })">Tambah Komponen</button>
                        <button type="submit" class="btn btn-primary btn-sm">Simpan BOM</button>
                    </div>
                </form>
            </div>
        @endcan

        <div class="card border border-base-300 bg-base-100 shadow-sm">
            <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-base-300 p-4">
                <div class="form-control">
                    <label class="label"><span class="label-text text-xs font-semibold uppercase">Status</span></label>
                    <select name="status" class="select select-bordered select-sm">
                        <option value="">Semua</option>
                        <option value="AKTIF" @selected($status === 'AKTIF')>Aktif</option>
                        <option value="NONAKTIF" @selected($status === 'NONAKTIF')>Nonaktif</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Tampilkan</button>
            </form>

            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nama</th>
                            <th>Bahan Hasil</th>
                            <th>Versi</th>
                            <th class="text-end">Hasil per Set</th>
                            <th>Komponen</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($daftar as $item)
                            <tr>
                                <td class="font-mono text-xs">{{ $item->kode }}</td>
                                <td>{{ $item->nama }}</td>
                                <td>{{ $item->bahan?->nama }}</td>
                                <td>{{ $item->versi }}</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($item->jumlah_hasil, 4, ',', '.'), '0'), ',') }}</td>
                                <td>
                                    <ul class="list-inside list-disc text-xs">
                                        @foreach ($item->details as $detail)
                                            <li>
                                                {{ $detail->bahan?->nama }}
                                                &mdash; {{ rtrim(rtrim(number_format($detail->jumlah, 4, ',', '.'), '0'), ',') }}
                                                {{ $detail->satuan }}
                                            </li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td>
                                    <span class="badge badge-sm {{ $item->isAktif() ? 'badge-success' : 'badge-ghost' }}">{{ $item->status }}</span>
                                </td>
                                <td class="text-end">
                                    <div class="flex flex-wrap justify-end gap-1">
                                        @can('update', $item)
                                            <form method="POST" action="{{ route('bom.status', $item) }}">
                                                @csrf
                                                <input type="hidden" name="status" value="{{ $item->isAktif() ? 'NONAKTIF' : 'AKTIF' }}">
                                                <button type="submit" class="btn btn-ghost btn-xs">
                                                    {{ $item->isAktif() ? 'Nonaktifkan' : 'Aktifkan' }}
                                                </button>
                                            </form>
                                        @endcan
                                        @can('delete', $item)
                                            <form method="POST" action="{{ route('bom.destroy', $item) }}"
                                                onsubmit="return confirm('Hapus BOM {{ $item->kode }}?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-ghost btn-xs text-error">Hapus</button>
                                            </form>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-6 text-center text-base-content/50">Belum ada BOM tersimpan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 pb-4">{{ $daftar->links() }}</div>
        </div>
    </div>
@endsection
