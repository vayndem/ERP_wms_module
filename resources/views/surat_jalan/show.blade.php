@extends('layouts.app')

@section('content')
    <div class="content-page">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-2xl font-bold">Surat Jalan {{ $suratJalan->nomor }}</h3>
                <p class="text-base-content/60">
                    {{ $suratJalan->pelanggan?->nama }} &middot; {{ $suratJalan->tanggal?->format('d-m-Y') }} &middot; Gudang {{ $suratJalan->gudang?->nama }}
                    <span class="badge {{ $suratJalan->status === 'POSTED' ? 'badge-success' : 'badge-ghost' }}">{{ $suratJalan->status }}</span>
                </p>
            </div>
            @can('post', $suratJalan)
                <form method="POST" action="{{ route('surat-jalan.post', $suratJalan) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">Posting Surat Jalan</button>
                </form>
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

        <div class="card mb-4 border border-base-300 bg-base-100 shadow-sm">
            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead><tr><th>Bahan</th><th class="text-end">Jumlah</th><th class="text-end">Sudah Difaktur</th><th class="text-end">Harga</th><th class="text-end">HPP</th></tr></thead>
                    <tbody>
                        @foreach ($suratJalan->details as $detail)
                            <tr>
                                <td>
                                    {{ $detail->bahan?->nama }}
                                    @if ($detail->alokasi->isNotEmpty())
                                        <div class="text-xs text-base-content/50">
                                            @foreach ($detail->alokasi as $alokasi)
                                                <span class="badge badge-ghost badge-xs">{{ number_format($alokasi->jumlah, 2, ',', '.') }} @ Rp {{ number_format($alokasi->harga_satuan, 0, ',', '.') }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                </td>
                                <td class="text-end">{{ number_format($detail->jumlah, 2, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($detail->jumlah_terfaktur, 2, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($detail->harga_satuan, 0, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($detail->hpp, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="bg-base-200/40 font-bold">
                            <td colspan="4" class="text-end">Total Harga Pokok</td>
                            <td class="text-end">Rp {{ number_format($suratJalan->total_hpp, 0, ',', '.') }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        @can('faktur', $suratJalan)
            @if ($belumTerfaktur->isNotEmpty())
                <form method="POST" action="{{ route('surat-jalan.faktur', $suratJalan) }}" class="card mb-4 border border-base-300 bg-base-100 p-4 shadow-sm">
                    @csrf
                    <h4 class="mb-3 font-semibold">Buat Faktur Penjualan</h4>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <div class="form-control">
                            <label class="label"><span class="label-text">Tanggal Faktur</span></label>
                            <input type="date" name="tanggal" value="{{ today()->format('Y-m-d') }}" required class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Jatuh Tempo</span></label>
                            <input type="date" name="jatuh_tempo" class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Nomor Seri Faktur Pajak</span></label>
                            <input type="text" name="no_faktur_pajak" value="{{ old('no_faktur_pajak') }}" placeholder="17 digit angka" maxlength="30" class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Tanggal Faktur Pajak</span></label>
                            <input type="date" name="tanggal_faktur_pajak" value="{{ old('tanggal_faktur_pajak') }}" class="input input-bordered input-sm">
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">PPnBM</span></label>
                            <input type="number" step="0.01" min="0" name="ppnbm" value="{{ old('ppnbm', 0) }}" class="input input-bordered input-sm">
                        </div>
                    </div>
                    <div class="mt-3"><button type="submit" class="btn btn-primary btn-sm">Buat Faktur</button></div>
                </form>
            @endif
        @endcan

        @can('retur', $suratJalan)
            <form method="POST" action="{{ route('surat-jalan.retur', $suratJalan) }}" class="card border border-base-300 bg-base-100 p-4 shadow-sm">
                @csrf
                <h4 class="mb-3 font-semibold">Retur Penjualan</h4>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div class="form-control">
                        <label class="label"><span class="label-text">Tanggal Retur</span></label>
                        <input type="date" name="tanggal" value="{{ today()->format('Y-m-d') }}" required class="input input-bordered input-sm">
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Alasan</span></label>
                        <input type="text" name="alasan" minlength="10" required placeholder="Minimal 10 karakter" class="input input-bordered input-sm">
                    </div>
                </div>
                <div class="mt-3 overflow-x-auto">
                    <table class="table table-sm">
                        <thead><tr><th>Bahan</th><th class="text-end">Dikirim</th><th class="w-40">Jumlah Retur</th></tr></thead>
                        <tbody>
                            @foreach ($suratJalan->details as $i => $detail)
                                <tr>
                                    <td>{{ $detail->bahan?->nama }}</td>
                                    <td class="text-end">{{ number_format($detail->jumlah, 2, ',', '.') }}</td>
                                    <td>
                                        <input type="hidden" name="details[{{ $i }}][surat_jalan_detail_id]" value="{{ $detail->id }}">
                                        <input type="number" step="0.000001" min="0" max="{{ $detail->jumlah }}" name="details[{{ $i }}][jumlah]" value="0" class="input input-bordered input-sm w-full">
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3"><button type="submit" class="btn btn-error btn-sm">Catat Retur</button></div>
            </form>
        @endcan
    </div>
@endsection
