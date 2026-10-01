@extends('layouts.app')

@section('content')
    <div class="content-page">
        <div class="mb-4">
            <h3 class="text-2xl font-bold">Pesanan Penjualan {{ $pesanan->nomor }}</h3>
            <p class="text-base-content/60">
                {{ $pesanan->pelanggan?->nama }} &middot; {{ $pesanan->tanggal?->format('d-m-Y') }} &middot; Gudang {{ $pesanan->gudang?->nama }}
                &middot; Sales {{ $pesanan->sales?->name ?? 'belum ditandai' }}
                <span class="badge {{ $pesanan->status === 'OPEN' ? 'badge-warning' : 'badge-success' }}">{{ $pesanan->status }}</span>
                @if ($pesanan->plafon_dilampaui)
                    <span class="badge badge-error">Menembus plafon kredit</span>
                @endif
            </p>
        </div>
        @if ($pesanan->plafon_dilampaui)
            <div class="alert alert-warning mb-4">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    <div class="font-semibold">Pesanan ini dibuat melewati plafon kredit pelanggan.</div>
                    <div class="text-sm">
                        Alasan: {{ $pesanan->alasan_plafon ?: 'tidak dicatat' }}
                        &middot; disetujui {{ $pesanan->penembusPlafon?->name ?? 'Super Admin' }}
                        @if ($pesanan->eksposur_saat_dibuat !== null)
                            &middot; eksposur sebelum pesanan ini Rp {{ number_format($pesanan->eksposur_saat_dibuat, 2, ',', '.') }}
                        @endif
                    </div>
                </div>
            </div>
        @endif
        @if (session('success'))
            <div class="alert alert-success mb-4"><i class="fa-solid fa-circle-check"></i><span>{{ session('success') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error mb-4">
                <i class="fa-solid fa-circle-exclamation"></i>
                <ul class="list-inside list-disc">@foreach ($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach</ul>
            </div>
        @endif

        <div class="card mb-4 border border-base-300 bg-base-100 shadow-sm">
            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead><tr><th>Bahan</th><th class="text-end">Dipesan</th><th class="text-end">Terkirim</th><th class="text-end">Sisa</th><th class="text-end">Harga</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        @foreach ($pesanan->details as $detail)
                            <tr>
                                <td>{{ $detail->bahan?->nama }}</td>
                                <td class="text-end">{{ number_format($detail->jumlah, 2, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($detail->jumlah_terkirim, 2, ',', '.') }}</td>
                                <td class="text-end {{ $detail->sisaKirim() > 0 ? 'text-warning font-semibold' : '' }}">{{ number_format($detail->sisaKirim(), 2, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($detail->harga_satuan, 0, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($detail->total_harga, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-base-200/40 font-bold">
                            <td colspan="5" class="text-end">DPP / PPN / Total</td>
                            <td class="text-end">
                                Rp {{ number_format($pesanan->total_dpp, 0, ',', '.') }} /
                                Rp {{ number_format($pesanan->total_ppn, 0, ',', '.') }} /
                                Rp {{ number_format($pesanan->grand_total, 0, ',', '.') }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        @can('kirim', $pesanan)
            <form method="POST" action="{{ route('pesanan-penjualan.kirim', $pesanan) }}" class="card mb-4 border border-base-300 bg-base-100 p-4 shadow-sm">
                @csrf
                <h4 class="mb-3 font-semibold">Buat Surat Jalan</h4>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                    <div class="form-control">
                        <label class="label"><span class="label-text">Tanggal Kirim</span></label>
                        <input type="date" name="tanggal" value="{{ today()->format('Y-m-d') }}" required class="input input-bordered input-sm">
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Nomor Kendaraan</span></label>
                        <input type="text" name="nomor_kendaraan" class="input input-bordered input-sm">
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Pengirim</span></label>
                        <input type="text" name="pengirim" class="input input-bordered input-sm">
                    </div>
                </div>

                <div class="mt-3 overflow-x-auto">
                    <table class="table table-sm">
                        <thead><tr><th>Bahan</th><th class="text-end">Sisa Pesanan</th><th class="w-40">Jumlah Kirim</th></tr></thead>
                        <tbody>
                            @foreach ($pesanan->details as $i => $detail)
                                <tr>
                                    <td>{{ $detail->bahan?->nama }}</td>
                                    <td class="text-end">{{ number_format($detail->sisaKirim(), 2, ',', '.') }}</td>
                                    <td>
                                        <input type="hidden" name="details[{{ $i }}][pesanan_penjualan_detail_id]" value="{{ $detail->id }}">
                                        <input type="number" step="0.000001" min="0" max="{{ $detail->sisaKirim() }}" name="details[{{ $i }}][jumlah]" value="{{ $detail->sisaKirim() }}" class="input input-bordered input-sm w-full">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-3"><button type="submit" class="btn btn-primary btn-sm">Buat Surat Jalan</button></div>
            </form>
        @endcan

        <div class="card border border-base-300 bg-base-100 shadow-sm">
            <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Surat Jalan</h4></div>
            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead><tr><th>Nomor</th><th>Tanggal</th><th class="text-end">HPP</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($pesanan->suratJalan as $sj)
                            <tr>
                                <td><a href="{{ route('surat-jalan.show', $sj) }}" class="link link-primary font-mono text-xs">{{ $sj->nomor }}</a></td>
                                <td>{{ $sj->tanggal?->format('d-m-Y') }}</td>
                                <td class="text-end">Rp {{ number_format($sj->total_hpp, 0, ',', '.') }}</td>
                                <td><span class="badge {{ $sj->status === 'POSTED' ? 'badge-success' : 'badge-ghost' }}">{{ $sj->status }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-4 text-center text-base-content/50">Belum ada surat jalan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
