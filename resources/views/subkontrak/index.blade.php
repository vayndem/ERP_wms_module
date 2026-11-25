@extends('layouts.app')

@section('content')
    <div class="content-page" x-data="{ baris: [{ bahan_id: '', jumlah: 1 }] }">
        <div class="mb-4">
            <h3 class="text-2xl font-bold">Pengiriman Subkontrak</h3>
            <p class="text-base-content/60">
                Barang tetap milik kita selama dikerjakan vendor. Stoknya pindah ke Gudang Subkontrak lewat transfer biasa,
                jadi nilai layer dan saldo gudang tetap cocok.
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

        @if ($terlambat->isNotEmpty())
            <div class="alert alert-warning mb-4">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>{{ $terlambat->count() }} pengiriman subkontrak lewat dari estimasi kembali dan barangnya masih di vendor.</span>
            </div>
        @endif

        @can('create', App\Models\PengirimanSubkontrak::class)
            <form method="POST" action="{{ route('subkontrak.store') }}" class="card mb-4 border border-base-300 bg-base-100 p-4 shadow-sm">
                @csrf
                <h4 class="mb-3 font-semibold">Kirim Barang ke Vendor</h4>

                @if ($tanpaGudang)
                    <p class="text-sm text-base-content/60">
                        Akun Anda belum diberi gudang mana pun, jadi belum ada stok yang bisa dikirim. Minta admin menambahkan pembagian gudang.
                    </p>
                @else
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div class="form-control">
                            <label class="label"><span class="label-text">Tanggal</span></label>
                            <input type="date" name="tanggal" value="{{ old('tanggal', today()->format('Y-m-d')) }}" required class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Vendor</span></label>
                            <select name="supplier_id" data-app-picker required class="select select-bordered select-sm">
                                <option value="">Pilih vendor</option>
                                @foreach ($supplier as $item)
                                    <option value="{{ $item->id }}" @selected(old('supplier_id') == $item->id)>{{ $item->nama }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Gudang asal</span></label>
                            <select name="gudang_asal_id" required class="select select-bordered select-sm">
                                @foreach ($gudang as $item)
                                    <option value="{{ $item->id }}" @selected(old('gudang_asal_id') == $item->id)>{{ $item->nama }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Estimasi kembali</span></label>
                            <input type="date" name="estimasi_kembali" value="{{ old('estimasi_kembali') }}" class="input input-bordered input-sm">
                        </div>
                        <div class="form-control md:col-span-2">
                            <label class="label"><span class="label-text">Keperluan</span></label>
                            <input type="text" name="keperluan" maxlength="191" value="{{ old('keperluan') }}"
                                placeholder="Contoh: jasa bordir, finishing cat" class="input input-bordered input-sm">
                        </div>
                    </div>

                    <div class="mt-4 overflow-x-auto">
                        <table class="table table-sm">
                            <thead><tr><th>Bahan</th><th class="text-end">Jumlah</th><th></th></tr></thead>
                            <tbody>
                                <template x-for="(item, i) in baris" :key="i">
                                    <tr>
                                        <td>
                                            <select :name="`details[${i}][bahan_id]`" data-app-picker required class="select select-bordered select-sm w-full">
                                                <option value="">Pilih bahan</option>
                                                @foreach ($bahan as $item)
                                                    <option value="{{ $item->id }}">{{ $item->nama }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="text-end">
                                            <input type="number" step="0.000001" min="0.000001" :name="`details[${i}][jumlah]`"
                                                x-model="item.jumlah" required class="input input-bordered input-sm w-32 text-end">
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-ghost btn-xs" x-show="baris.length > 1"
                                                @click="baris.splice(i, 1)">&times;</button>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-2">
                        <button type="button" class="btn btn-ghost btn-sm" @click="baris.push({ bahan_id: '', jumlah: 1 })">Tambah baris</button>
                        <button type="submit" class="btn btn-primary btn-sm">Kirim ke Vendor</button>
                    </div>
                @endif
            </form>
        @endcan

        <div class="card border border-base-300 bg-base-100 shadow-sm">
            <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Riwayat Pengiriman</h4></div>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Nomor</th><th>Tanggal</th><th>Vendor</th><th>Gudang Asal</th>
                            <th>Estimasi Kembali</th><th>Status</th><th>Barang</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($daftar as $baris)
                            <tr>
                                <td class="font-mono text-xs">{{ $baris->nomor }}</td>
                                <td>{{ $baris->tanggal?->format('d-m-Y') }}</td>
                                <td>{{ $baris->supplier?->nama }}</td>
                                <td>{{ $baris->gudangAsal?->nama }}</td>
                                <td class="{{ $baris->terlambat() ? 'text-error font-semibold' : '' }}">
                                    {{ $baris->estimasi_kembali?->format('d-m-Y') ?? '-' }}
                                </td>
                                <td>
                                    <span class="badge badge-sm {{ $baris->status === 'SELESAI' ? 'badge-success' : 'badge-warning' }}">
                                        {{ $baris->status }}
                                    </span>
                                </td>
                                <td class="text-xs">
                                    @foreach ($baris->details as $detail)
                                        <div>
                                            {{ $detail->bahan?->nama }}:
                                            {{ number_format($detail->jumlah, 2, ',', '.') }} dikirim,
                                            {{ number_format($detail->jumlah_kembali, 2, ',', '.') }} kembali
                                        </div>
                                    @endforeach
                                </td>
                                <td class="text-end">
                                    @can('terima', $baris)
                                        <form method="POST" action="{{ route('subkontrak.terima', $baris) }}"
                                            onsubmit="return confirm('Catat pengembalian {{ $baris->nomor }}?')">
                                            @csrf
                                            <input type="hidden" name="tanggal" value="{{ today()->format('Y-m-d') }}">
                                            @foreach ($baris->details as $detail)
                                                <input type="number" step="0.000001" min="0" name="kembali[{{ $detail->id }}]"
                                                    value="{{ $detail->sisaDiVendor() }}" class="input input-bordered input-xs w-24">
                                            @endforeach
                                            <button type="submit" class="btn btn-primary btn-xs">Terima Kembali</button>
                                        </form>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="py-6 text-center text-base-content/50">
                                    @if ($tanpaGudang)
                                        Belum ada gudang yang ditugaskan ke akun Anda.
                                    @else
                                        Belum ada pengiriman subkontrak tercatat.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="p-4">{{ $daftar->links() }}</div>
        </div>
    </div>
@endsection
