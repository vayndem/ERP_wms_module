@extends('layouts.app')

@section('content')
    <div class="content-page">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-2xl font-bold">Pelanggan</h3>
                <p class="text-base-content/60">Master pelanggan untuk pesanan penjualan, surat jalan, dan piutang usaha.</p>
            </div>
            @can('create', App\Models\Pelanggan::class)
                <a href="{{ route('pelanggan.create') }}" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Pelanggan Baru</a>
            @endcan
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

        <div class="card border border-base-300 bg-base-100 shadow-sm">
            <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-base-300 p-4">
                <div class="form-control">
                    <label class="label"><span class="label-text text-xs font-semibold uppercase">Cari</span></label>
                    <input type="text" name="cari" value="{{ $cari }}" placeholder="Nama atau kode" class="input input-bordered input-sm">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Cari</button>
            </form>

            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Kode</th><th>Nama</th><th>NPWP</th><th>Kontak</th>
                            <th class="text-end">Termin</th><th class="text-end">Plafon</th>
                            <th class="text-end">Piutang Berjalan</th><th class="text-end">Eksposur</th>
                            <th class="text-end">Sisa Plafon</th><th>Status</th><th class="text-end">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pelanggan as $baris)
                            <tr>
                                <td class="font-mono text-xs">{{ $baris->kode }}</td>
                                <td class="font-medium">{{ $baris->nama }}</td>
                                <td class="text-xs">{{ $baris->npwp ?: '-' }}</td>
                                <td class="text-xs">{{ $baris->telp ?: '-' }}<br>{{ $baris->email }}</td>
                                <td class="text-end">{{ $baris->termin_hari }} hari</td>
                                <td class="text-end">Rp {{ number_format($baris->plafon_kredit, 0, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($baris->piutangBerjalan(), 0, ',', '.') }}</td>
                                @php($hitung = $eksposur[$baris->id])
                                <td class="text-end">Rp {{ number_format($hitung['total'], 0, ',', '.') }}</td>
                                <td class="text-end {{ $hitung['sisa_plafon'] !== null && $hitung['sisa_plafon'] < 0 ? 'text-error font-semibold' : '' }}">
                                    @if ($hitung['tanpa_batas'])
                                        <span class="text-base-content/50">tanpa batas</span>
                                    @else
                                        Rp {{ number_format($hitung['sisa_plafon'], 0, ',', '.') }}
                                    @endif
                                </td>
                                <td>
                                    <span class="badge {{ $baris->is_active ? 'badge-success' : 'badge-ghost' }}">{{ $baris->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                                </td>
                                <td class="text-end">
                                    @can('update', $baris)
                                        <a href="{{ route('pelanggan.edit', $baris) }}" class="btn btn-ghost btn-xs">Ubah</a>
                                    @endcan
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="py-6 text-center text-base-content/50">Belum ada pelanggan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 pb-4">{{ $pelanggan->links() }}</div>
        </div>
    </div>
@endsection
