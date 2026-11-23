@extends('layouts.app')

@section('content')
    <div class="content-page">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-2xl font-bold">Perintah Kerja Produksi</h3>
                <p class="text-base-content/60">Produksi selalu untuk pesanan pelanggan. Biaya material dan jasa menumpuk di sini sampai barangnya dikirim.</p>
            </div>
            <div class="flex items-center gap-3">
                <div class="rounded-lg border border-base-300 px-4 py-2 text-end">
                    <div class="text-xs uppercase text-base-content/60">Barang Dalam Proses</div>
                    <div class="text-xl font-bold">Rp {{ number_format($totalWip, 0, ',', '.') }}</div>
                </div>
                @can('create', App\Models\DataPesanan::class)
                    <a href="{{ route('data-pesanan.create') }}" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Perintah Kerja Baru</a>
                @endcan
            </div>
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
                    <label class="label"><span class="label-text text-xs font-semibold uppercase">Status</span></label>
                    <select name="status" class="select select-bordered select-sm">
                        <option value="">Semua</option>
                        @foreach (['DRAFT' => 'Draft', 'DIRILIS' => 'Dirilis', 'SELESAI' => 'Selesai (belum terkirim penuh)', 'DITUTUP' => 'Ditutup', 'DIBATALKAN' => 'Dibatalkan'] as $kode => $label)
                            <option value="{{ $kode }}" @selected($status === $kode)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Tampilkan</button>
            </form>

            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Nomor</th><th>Tanggal</th><th>Pelanggan</th><th>Produk</th>
                            <th class="text-end">Rencana</th><th class="text-end">Selesai</th><th class="text-end">Terkirim</th>
                            <th class="text-end">Saldo WIP</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($pesanan as $baris)
                            <tr>
                                <td><a href="{{ route('data-pesanan.show', $baris) }}" class="link link-primary font-mono text-xs">{{ $baris->nomor }}</a></td>
                                <td>{{ $baris->tanggal?->format('d-m-Y') }}</td>
                                <td>{{ $baris->pesananPenjualan?->pelanggan?->nama }}</td>
                                <td>{{ $baris->bahanHasil?->nama }}</td>
                                <td class="text-end">{{ number_format($baris->jumlah_rencana, 2, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($baris->jumlah_selesai, 2, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($baris->jumlah_terkirim, 2, ',', '.') }}</td>
                                <td class="text-end font-semibold">Rp {{ number_format($saldoWip[$baris->id] ?? 0, 0, ',', '.') }}</td>
                                <td>
                                    <span class="badge {{ $baris->status === 'DITUTUP' ? 'badge-success' : ($baris->status === 'DIBATALKAN' ? 'badge-error' : ($baris->status === 'SELESAI' ? 'badge-info' : 'badge-warning')) }}">{{ $baris->status }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="py-6 text-center text-base-content/50">Belum ada perintah kerja.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 pb-4">{{ $pesanan->links() }}</div>
        </div>
    </div>
@endsection
