@extends('layouts.app')

@section('content')
    <div class="content-page">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-2xl font-bold">Faktur Penjualan {{ $faktur->nomor }}</h3>
                <p class="text-base-content/60">
                    {{ $faktur->pelanggan?->nama }} &middot; {{ $faktur->tanggal?->format('d-m-Y') }} &middot; jatuh tempo {{ $faktur->jatuh_tempo?->format('d-m-Y') }}
                    <span class="badge {{ $faktur->status === 'PAID' ? 'badge-success' : ($faktur->status === 'DRAFT' ? 'badge-ghost' : 'badge-warning') }}">{{ $faktur->status }}</span>
                </p>
            </div>
            <div class="flex gap-2">
                @can('post', $faktur)
                    <form method="POST" action="{{ route('faktur-penjualan.post', $faktur) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm">Posting Faktur</button>
                    </form>
                @endcan
                @can('delete', $faktur)
                    <form method="POST" action="{{ route('faktur-penjualan.destroy', $faktur) }}"
                        onsubmit="return confirm('Hapus faktur draft ini? Surat jalannya bisa difakturkan ulang setelah dihapus.')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-ghost btn-sm">Hapus Draft</button>
                    </form>
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

        <div class="card mb-4 border border-base-300 bg-base-100 shadow-sm">
            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead><tr><th>Bahan</th><th class="text-end">Jumlah</th><th class="text-end">Harga</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        @foreach ($faktur->details as $detail)
                            <tr>
                                <td>{{ $detail->bahan?->nama }}</td>
                                <td class="text-end">{{ number_format($detail->jumlah, 2, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($detail->harga_satuan, 0, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($detail->total_harga, 0, ',', '.') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr><td colspan="3" class="text-end">DPP</td><td class="text-end">Rp {{ number_format($faktur->total_dpp, 0, ',', '.') }}</td></tr>
                        <tr><td colspan="3" class="text-end">PPN Keluaran ({{ rtrim(rtrim(number_format($faktur->tarif_ppn, 2, ',', '.'), '0'), ',') }}%)</td><td class="text-end">Rp {{ number_format($faktur->total_ppn, 0, ',', '.') }}</td></tr>
                        <tr class="bg-base-200/40 font-bold"><td colspan="3" class="text-end">Grand Total</td><td class="text-end">Rp {{ number_format($faktur->grand_total, 0, ',', '.') }}</td></tr>
                        <tr class="font-bold"><td colspan="3" class="text-end">Sisa Tagihan</td><td class="text-end">Rp {{ number_format($faktur->sisa_tagihan, 0, ',', '.') }}</td></tr>
                    </tfoot>
                </table>
            </div>
            @if ($faktur->no_faktur_pajak)
                <div class="px-4 pb-4 text-xs text-base-content/60">Nomor seri faktur pajak: <span class="font-mono">{{ $faktur->no_faktur_pajak }}</span></div>
            @endif
        </div>

        @can('terimaPembayaran', $faktur)
            @if ($uangMuka->isNotEmpty() && $faktur->sisa_tagihan > 0)
                <form method="POST" action="{{ route('faktur-penjualan.uang-muka', $faktur) }}" class="card mb-4 border border-info/40 bg-info/5 p-4 shadow-sm">
                    @csrf
                    <h4 class="mb-1 font-semibold">Pakai Uang Muka Pelanggan</h4>
                    <p class="mb-3 text-sm text-base-content/60">
                        Saldo uang muka {{ $faktur->pelanggan?->nama }}: Rp {{ number_format($uangMuka->sum('sisa'), 2, ',', '.') }}
                    </p>
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                        <div class="form-control">
                            <label class="label"><span class="label-text">Tanggal</span></label>
                            <input type="date" name="tanggal" value="{{ today()->format('Y-m-d') }}" required class="input input-bordered input-sm">
                        </div>
                        <div class="form-control md:col-span-2">
                            <label class="label"><span class="label-text">Uang muka</span></label>
                            <select name="uang_muka_id" required class="select select-bordered select-sm">
                                @foreach ($uangMuka as $um)
                                    <option value="{{ $um->id }}">{{ $um->nomor }} &middot; sisa Rp {{ number_format($um->sisa, 2, ',', '.') }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-control">
                            <label class="label"><span class="label-text">Jumlah dipakai</span></label>
                            <input type="number" step="0.01" min="0.01" name="jumlah"
                                value="{{ min((float) $faktur->sisa_tagihan, (float) $uangMuka->sum('sisa')) }}" required class="input input-bordered input-sm">
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-info btn-sm">Pakai Uang Muka</button>
                    </div>
                </form>
            @endif
            <form method="POST" action="{{ route('faktur-penjualan.bayar', $faktur) }}" class="card mb-4 border border-base-300 bg-base-100 p-4 shadow-sm">
                @csrf
                <h4 class="mb-3 font-semibold">Terima Pembayaran</h4>
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div class="form-control">
                        <label class="label"><span class="label-text">Tanggal</span></label>
                        <input type="date" name="tanggal" value="{{ today()->format('Y-m-d') }}" required class="input input-bordered input-sm">
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Akun Kas/Bank</span></label>
                        <select name="coa_kas_bank_id" required class="select select-bordered select-sm">
                            <option value="">Pilih akun</option>
                            @foreach ($kasBank as $akun)
                                <option value="{{ $akun->id }}">{{ $akun->kode_akun }} &middot; {{ $akun->nama_akun }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Jumlah</span></label>
                        <input type="number" step="0.01" min="0.01" name="jumlah" value="{{ $faktur->sisa_tagihan }}" required class="input input-bordered input-sm">
                        <span class="label-text-alt mt-1 text-base-content/60">Kelebihan bayar otomatis diparkir sebagai uang muka pelanggan.</span>
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Referensi</span></label>
                        <input type="text" name="referensi" class="input input-bordered input-sm">
                    </div>
                </div>
                <div class="mt-3"><button type="submit" class="btn btn-primary btn-sm">Catat Penerimaan</button></div>
            </form>
        @endcan

        <div class="card border border-base-300 bg-base-100 shadow-sm">
            <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Riwayat Penerimaan</h4></div>
            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead><tr><th>Nomor</th><th>Tanggal</th><th>Akun</th><th>Referensi</th><th class="text-end">Jumlah</th></tr></thead>
                    <tbody>
                        @forelse ($faktur->pembayaran as $bayar)
                            <tr>
                                <td class="font-mono text-xs">{{ $bayar->nomor }}</td>
                                <td>{{ $bayar->tanggal?->format('d-m-Y') }}</td>
                                <td>{{ $bayar->kasBank?->nama_akun }}</td>
                                <td class="text-xs">{{ $bayar->referensi ?: '-' }}</td>
                                <td class="text-end">Rp {{ number_format($bayar->jumlah, 0, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-4 text-center text-base-content/50">Belum ada penerimaan.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
