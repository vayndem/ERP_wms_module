@extends('layouts.app')

@section('content')
    <div class="content-page">
        <div class="mb-4">
            <h3 class="text-2xl font-bold">Cross Dock</h3>
            <p class="text-base-content/60">
                Barang yang baru diterima dan langsung dikunci untuk pesanan penjualan yang menunggu, tanpa lewat penyimpanan.
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

        <div class="card mb-4 border border-base-300 bg-base-100 shadow-sm">
            <form method="GET" class="flex flex-wrap items-end gap-3 p-4">
                <div class="form-control">
                    <label class="label"><span class="label-text text-xs font-semibold uppercase">Gudang</span></label>
                    <select name="gudang_id" class="select select-bordered select-sm">
                        <option value="">Semua gudang</option>
                        @foreach ($gudang as $item)
                            <option value="{{ $item->id }}" @selected($gudangId === $item->id)>{{ $item->nama }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Tampilkan</button>
            </form>
        </div>

        <div class="card mb-4 border border-base-300 bg-base-100 shadow-sm">
            <div class="border-b border-base-300 p-4">
                <h4 class="font-semibold">Usulan Cross Dock</h4>
                <p class="text-sm text-base-content/60">
                    Penerimaan {{ \App\Services\CrossDockService::HARI_TERAKHIR }} hari terakhir yang cocok dengan pesanan penjualan terbuka di gudang yang sama.
                </p>
            </div>
            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>LPB</th>
                            <th>Diterima</th>
                            <th>Bahan</th>
                            <th>Pesanan</th>
                            <th>Pelanggan</th>
                            <th class="text-end">Sisa Pesanan</th>
                            <th class="text-end">Sisa LPB</th>
                            <th class="text-end">Stok Bebas</th>
                            <th class="w-64">Jumlah</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($saran as $baris)
                            <tr>
                                <td class="font-mono text-xs">{{ $baris['id_lpb'] }}</td>
                                <td>{{ \Illuminate\Support\Carbon::parse($baris['tanggal_terima'])->format('d-m-Y') }}</td>
                                <td>{{ $baris['bahan'] }}</td>
                                <td class="font-mono text-xs">{{ $baris['pesanan'] }}</td>
                                <td>{{ $baris['pelanggan'] }}</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($baris['sisa_pesanan'], 4, ',', '.'), '0'), ',') }}</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($baris['sisa_penerimaan'], 4, ',', '.'), '0'), ',') }}</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($baris['stok_bebas'], 4, ',', '.'), '0'), ',') }}</td>
                                <td>
                                    <form method="POST" action="{{ route('cross-dock.store') }}" class="flex flex-wrap items-center gap-2">
                                        @csrf
                                        <input type="hidden" name="penerimaan_barang_detail_id" value="{{ $baris['penerimaan_barang_detail_id'] }}">
                                        <input type="hidden" name="pesanan_penjualan_detail_id" value="{{ $baris['pesanan_penjualan_detail_id'] }}">
                                        <input type="number" step="0.000001" name="jumlah" value="{{ $baris['usul'] }}"
                                            class="input input-bordered input-xs w-28" required>
                                        <button type="submit" class="btn btn-primary btn-xs">Kunci</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="py-6 text-center text-base-content/50">
                                    @if ($tanpaGudang)
                                        Akun Anda belum diberi gudang mana pun, jadi belum ada penerimaan yang bisa di-cross dock. Minta admin menambahkan pembagian gudang.
                                    @else
                                        Tidak ada penerimaan terbaru yang cocok dengan pesanan penjualan terbuka.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border border-base-300 bg-base-100 shadow-sm">
            <div class="border-b border-base-300 p-4">
                <h4 class="font-semibold">Cross Dock Tercatat</h4>
            </div>
            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Nomor</th>
                            <th>Tanggal</th>
                            <th>Bahan</th>
                            <th>Gudang</th>
                            <th>Pesanan</th>
                            <th class="text-end">Jumlah</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($daftar as $baris)
                            <tr>
                                <td class="font-mono text-xs">{{ $baris->nomor }}</td>
                                <td>{{ $baris->tanggal?->format('d-m-Y') }}</td>
                                <td>{{ $baris->bahan?->nama }}</td>
                                <td>{{ $baris->gudang?->nama }}</td>
                                <td class="font-mono text-xs">{{ $baris->pesananDetail?->pesanan?->nomor }}</td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($baris->jumlah, 4, ',', '.'), '0'), ',') }}</td>
                                <td>
                                    <span class="badge badge-sm {{ $baris->status === 'DIKIRIM' ? 'badge-success' : ($baris->status === 'DIBATALKAN' ? 'badge-ghost' : 'badge-warning') }}">
                                        {{ $baris->status }}
                                    </span>
                                </td>
                                <td class="text-end">
                                    @can('batalkan', $baris)
                                        <form method="POST" action="{{ route('cross-dock.batalkan', $baris) }}"
                                            onsubmit="return confirm('Batalkan cross dock {{ $baris->nomor }} dan lepas reservasinya?')">
                                            @csrf
                                            <button type="submit" class="btn btn-ghost btn-xs text-error">Batalkan</button>
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
                                        Belum ada cross dock tercatat.
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="px-4 pb-4">{{ $daftar->links() }}</div>
        </div>
    </div>
@endsection
