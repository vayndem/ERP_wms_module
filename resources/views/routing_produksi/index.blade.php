@extends('layouts.app')

@section('content')
    <div class="content-page" x-data="{ baris: [{ urutan: 1 }] }">
        <div class="mb-4">
            <h3 class="text-2xl font-bold">Routing Produksi</h3>
            <p class="text-base-content/60">
                Urutan operasi per BOM beserta pusat kerjanya. Waktu standar dipakai untuk melihat beban kapasitas;
                biaya tenaga kerja dan overhead belum diserap ke Barang Dalam Proses.
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

        <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="card border border-base-300 bg-base-100 shadow-sm">
                <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Pusat Kerja</h4></div>

                @can('create', App\Models\PusatKerja::class)
                    <form method="POST" action="{{ route('routing-produksi.pusat-kerja') }}" class="space-y-3 p-4">
                        @csrf
                        <div class="form-control">
                            <label class="label"><span class="label-text">Kode</span></label>
                            <input type="text" name="kode" value="{{ old('kode') }}" required class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Nama</span></label>
                            <input type="text" name="nama" value="{{ old('nama') }}" required class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Gudang</span></label>
                            <select name="gudang_id" class="select select-bordered select-sm">
                                <option value="">Tidak terikat gudang</option>
                                @foreach ($gudang as $item)
                                    <option value="{{ $item->id }}" @selected(old('gudang_id') == $item->id)>{{ $item->nama }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Kapasitas (menit/hari)</span></label>
                            <input type="number" step="0.01" max="1440" name="kapasitas_menit_per_hari"
                                value="{{ old('kapasitas_menit_per_hari') }}" class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Tarif tenaga kerja / jam</span></label>
                            <input type="number" step="0.01" min="0" name="tarif_tenaga_kerja_per_jam"
                                value="{{ old('tarif_tenaga_kerja_per_jam', 0) }}" class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Tarif overhead / jam</span></label>
                            <input type="number" step="0.01" min="0" name="tarif_overhead_per_jam"
                                value="{{ old('tarif_overhead_per_jam', 0) }}" class="input input-bordered input-sm">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-full">Tambah Pusat Kerja</button>
                    </form>
                @endcan

                <div class="overflow-x-auto border-t border-base-300 p-4">
                    <table class="table table-sm">
                        <thead><tr><th>Kode</th><th>Nama</th><th class="text-end">Kapasitas</th><th class="text-end">Tarif TK/jam</th><th class="text-end">Tarif OH/jam</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @forelse ($pusatKerja as $item)
                                <tr>
                                    <td class="font-mono text-xs">{{ $item->kode }}</td>
                                    <td>{{ $item->nama }}<div class="text-xs text-base-content/50">{{ $item->gudang?->nama ?? 'lintas gudang' }}</div></td>
                                    <td class="text-end">{{ $item->kapasitas_menit_per_hari ? number_format($item->kapasitas_menit_per_hari, 0, ',', '.') : '-' }}</td>
                                    <td class="text-end">{{ number_format($item->tarif_tenaga_kerja_per_jam, 0, ',', '.') }}</td>
                                    <td class="text-end">{{ number_format($item->tarif_overhead_per_jam, 0, ',', '.') }}</td>
                                    <td><span class="badge badge-sm {{ $item->isAktif() ? 'badge-success' : 'badge-ghost' }}">{{ $item->status }}</span></td>
                                    <td class="text-end">
                                        @can('update', $item)
                                            <form method="POST" action="{{ route('routing-produksi.pusat-kerja.status', $item) }}">
                                                @csrf
                                                <input type="hidden" name="status" value="{{ $item->isAktif() ? 'NONAKTIF' : 'AKTIF' }}">
                                                <button type="submit" class="btn btn-ghost btn-xs">{{ $item->isAktif() ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                            </form>
                                        @endcan
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-4 text-center text-base-content/50">Belum ada pusat kerja.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card border border-base-300 bg-base-100 shadow-sm lg:col-span-2">
                <div class="border-b border-base-300 p-4">
                    <h4 class="font-semibold">Routing per BOM</h4>
                </div>

                <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-base-300 p-4">
                    <div class="form-control">
                        <label class="label"><span class="label-text text-xs font-semibold uppercase">BOM</span></label>
                        <select name="bom" class="select select-bordered select-sm" data-app-picker>
                            <option value="">Pilih BOM</option>
                            @foreach ($bomPilihan as $item)
                                <option value="{{ $item->id }}" @selected($bom && $bom->id === $item->id)>
                                    {{ $item->kode }} &middot; {{ $item->bahan?->nama }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">Tampilkan</button>
                </form>

                @if ($bom)
                    <div class="overflow-x-auto p-4">
                        <table class="table table-sm">
                            <thead><tr><th class="text-end">Urutan</th><th>Operasi</th><th>Pusat Kerja</th><th class="text-end">Waktu Standar (menit)</th></tr></thead>
                            <tbody>
                                @forelse ($bom->operasi->sortBy('urutan') as $operasi)
                                    <tr>
                                        <td class="text-end">{{ $operasi->urutan }}</td>
                                        <td>{{ $operasi->nama_operasi }}</td>
                                        <td>{{ $operasi->pusatKerja?->nama }}</td>
                                        <td class="text-end">{{ number_format($operasi->waktu_standar_menit, 2, ',', '.') }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="py-4 text-center text-base-content/50">BOM ini belum punya routing.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @can('update', $bom)
                        <form method="POST" action="{{ route('routing-produksi.operasi', $bom) }}" class="border-t border-base-300 p-4">
                            @csrf
                            <div class="overflow-x-auto">
                                <table class="table table-sm">
                                    <thead><tr><th class="w-20">Urutan</th><th>Operasi</th><th>Pusat Kerja</th><th class="w-32">Menit</th><th class="w-16"></th></tr></thead>
                                    <tbody>
                                        <template x-for="(item, index) in baris" :key="index">
                                            <tr>
                                                <td><input type="number" min="1" :name="`operasi[${index}][urutan]`" x-model="item.urutan" required class="input input-bordered input-sm w-full"></td>
                                                <td><input type="text" :name="`operasi[${index}][nama_operasi]`" required class="input input-bordered input-sm w-full"></td>
                                                <td>
                                                    <select :name="`operasi[${index}][pusat_kerja_id]`" required class="select select-bordered select-sm w-full">
                                                        <option value="">Pilih pusat kerja</option>
                                                        @foreach ($pusatKerja->where('status', 'AKTIF') as $item)
                                                            <option value="{{ $item->id }}">{{ $item->nama }}</option>
                                                        @endforeach
                                                    </select>
                                                </td>
                                                <td><input type="number" step="0.01" min="0" :name="`operasi[${index}][waktu_standar_menit]`" class="input input-bordered input-sm w-full"></td>
                                                <td><button type="button" class="btn btn-ghost btn-xs text-error" x-show="baris.length > 1" @click="baris.splice(index, 1)">Hapus</button></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button type="button" class="btn btn-outline btn-sm" @click="baris.push({ urutan: baris.length + 1 })">Tambah Operasi</button>
                                <button type="submit" class="btn btn-primary btn-sm">Simpan Routing</button>
                            </div>
                        </form>
                    @endcan
                @endif
            </div>
        </div>

        <div class="mb-4 card border border-base-300 bg-base-100 shadow-sm">
            <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Beban Pusat Kerja dari Perintah Kerja Berjalan</h4></div>
            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead><tr><th>Pusat Kerja</th><th class="text-end">Perintah Kerja</th><th class="text-end">Total Menit</th><th class="text-end">Kapasitas/Hari</th><th class="text-end">Setara Hari</th></tr></thead>
                    <tbody>
                        @forelse ($beban as $baris)
                            <tr>
                                <td>{{ $baris['pusat_kerja']->nama }}<div class="text-xs text-base-content/50 font-mono">{{ $baris['pusat_kerja']->kode }}</div></td>
                                <td class="text-end">{{ $baris['perintah_kerja'] }}</td>
                                <td class="text-end">{{ number_format($baris['menit'], 2, ',', '.') }}</td>
                                <td class="text-end">{{ $baris['kapasitas_harian'] ? number_format($baris['kapasitas_harian'], 0, ',', '.') : '-' }}</td>
                                <td class="text-end {{ ($baris['hari_kerja'] ?? 0) > 5 ? 'text-error' : '' }}">
                                    {{ $baris['hari_kerja'] === null ? 'kapasitas belum diisi' : number_format($baris['hari_kerja'], 2, ',', '.') }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-6 text-center text-base-content/50">Belum ada pusat kerja aktif.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border border-base-300 bg-base-100 shadow-sm">
            <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Routing Perintah Kerja</h4></div>

            <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-base-300 p-4">
                <div class="form-control">
                    <label class="label"><span class="label-text text-xs font-semibold uppercase">Perintah Kerja</span></label>
                    <select name="pesanan" class="select select-bordered select-sm" data-app-picker>
                        <option value="">Pilih perintah kerja</option>
                        @foreach ($pesananPilihan as $item)
                            <option value="{{ $item->id }}" @selected($pesanan && $pesanan->id === $item->id)>
                                {{ $item->nomor }} &middot; {{ $item->bahanHasil?->nama }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Tampilkan</button>
            </form>

            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead><tr><th class="text-end">Urutan</th><th>Operasi</th><th>Pusat Kerja</th><th class="text-end">Menit/Unit</th><th class="text-end">Total Menit</th></tr></thead>
                    <tbody>
                        @forelse ($routing['operasi'] ?? [] as $operasi)
                            <tr>
                                <td class="text-end">{{ $operasi['urutan'] }}</td>
                                <td>{{ $operasi['nama_operasi'] }}</td>
                                <td>{{ $operasi['pusat_kerja'] }}</td>
                                <td class="text-end">{{ number_format($operasi['waktu_standar_menit'], 2, ',', '.') }}</td>
                                <td class="text-end font-semibold">{{ number_format($operasi['total_menit'], 2, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-6 text-center text-base-content/50">
                                    {{ $pesanan ? 'Bahan hasil perintah kerja ini belum punya BOM aktif dengan routing.' : 'Belum ada perintah kerja dipilih.' }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
