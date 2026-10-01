@extends('layouts.app')

@section('content')
    <div class="content-page">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="text-2xl font-bold">Lacak Pembelian</h3>
                <p class="text-base-content/60">Dari nilai yang dibelanjakan pada satu penerimaan, berapa yang masih di gudang, berapa yang sudah jadi beban, dan berapa yang hilang jadi selisih opname.</p>
            </div>
            @if ($lpb)
                <div class="flex gap-2">
                    <a href="{{ route('lacak-pembelian.pdf', ['lpb' => $lpb->id_lpb]) }}" target="_blank" rel="noopener" class="btn btn-error btn-sm">
                        <i class="fa-solid fa-file-pdf"></i> PDF
                    </a>
                    <a href="{{ route('lacak-pembelian.excel', ['lpb' => $lpb->id_lpb]) }}" target="_blank" rel="noopener" class="btn btn-success btn-sm">
                        <i class="fa-solid fa-file-excel"></i> Excel
                    </a>
                </div>
            @endif
        </div>

        <div class="card border border-base-300 bg-base-100 shadow-sm">
            <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-base-300 p-4">
                <div class="form-control w-full sm:w-96">
                    <label class="label"><span class="label-text text-xs font-semibold uppercase">Penerimaan Barang</span></label>
                    <select name="lpb" data-app-picker class="select select-bordered select-sm">
                        <option value="">Pilih penerimaan</option>
                        @foreach ($pilihan as $opsi)
                            <option value="{{ $opsi->id_lpb }}" @selected($lpb && $lpb->id_lpb === $opsi->id_lpb)>
                                {{ $opsi->id_lpb }} &middot; {{ $opsi->tanggal?->format('d-m-Y') }}@if ($opsi->no_po) &middot; PO {{ $opsi->no_po }}@endif
                            </option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Telusuri</button>
            </form>

            @if (!$lpb)
                <div class="p-8 text-center text-base-content/50">Pilih satu penerimaan barang untuk melihat sebaran nilainya.</div>
            @else
                @php
                    $rupiah = fn ($nilai) => 'Rp ' . number_format($nilai, 0, ',', '.');
                @endphp

                <div class="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-lg border border-base-300 p-4">
                        <div class="text-xs uppercase text-base-content/60">Total Masuk</div>
                        <div class="text-xl font-bold">{{ $rupiah($data['total_nilai_masuk']) }}</div>
                        <div class="text-xs text-base-content/50">Pembelian {{ $rupiah($data['total_nilai_pembelian']) }} + biaya tambahan {{ $rupiah($data['total_biaya_tambahan']) }}</div>
                    </div>
                    <div class="rounded-lg border border-success/40 bg-success/5 p-4">
                        <div class="text-xs uppercase text-base-content/60">Masih Stok</div>
                        <div class="text-xl font-bold text-success">{{ $rupiah($data['total_sisa_stok']) }}</div>
                    </div>
                    <div class="rounded-lg border border-warning/40 bg-warning/5 p-4">
                        <div class="text-xs uppercase text-base-content/60">Jadi Beban (NPK)</div>
                        <div class="text-xl font-bold text-warning">{{ $rupiah($data['total_beban_npk']) }}</div>
                    </div>
                    <div class="rounded-lg border border-info/40 bg-info/5 p-4">
                        <div class="text-xs uppercase text-base-content/60">Terjual</div>
                        <div class="text-xl font-bold text-info">{{ $rupiah($data['total_terjual']) }}</div>
                    </div>
                    <div class="rounded-lg border border-warning/40 bg-warning/5 p-4">
                        <div class="text-xs uppercase text-base-content/60">Hilang di Jalan</div>
                        <div class="text-xl font-bold text-warning">{{ $rupiah($data['total_hilang_transit']) }}</div>
                    </div>
                    <div class="rounded-lg border border-error/40 bg-error/5 p-4">
                        <div class="text-xs uppercase text-base-content/60">Selisih Opname</div>
                        <div class="text-xl font-bold text-error">{{ $rupiah($data['total_selisih_opname']) }}</div>
                    </div>
                </div>

                <div class="overflow-x-auto p-4">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Bahan</th>
                                <th class="text-end">Jumlah</th>
                                <th class="text-end">Total Masuk</th>
                                <th class="text-end">Masih Stok</th>
                                <th class="text-end">Jadi Beban</th>
                                <th class="text-end">Terjual</th>
                                <th class="text-end">Selisih Opname</th>
                                <th class="text-end">Retur</th>
                                <th class="text-end">Hilang di Jalan</th>
                                <th class="text-end">Belum Terlacak</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($data['baris'] as $row)
                                <tr>
                                    <td>
                                        <div class="font-medium">{{ $row['bahan']?->nama ?? '-' }}</div>
                                        <div class="text-xs text-base-content/50">
                                            {{ $row['kategori']?->katnama ?? '-' }}
                                            @foreach ($row['sebaran_gudang'] as $sebaran)
                                                <span class="badge badge-ghost badge-xs">{{ $sebaran['gudang']?->nama ?? 'Gudang' }}: {{ $rupiah($sebaran['nilai']) }}</span>
                                            @endforeach
                                        </div>
                                    </td>
                                    <td class="text-end">{{ number_format($row['jumlah'], 2, ',', '.') }}</td>
                                    <td class="text-end">{{ $rupiah($row['nilai_masuk']) }}</td>
                                    <td class="text-end text-success">{{ $rupiah($row['sisa_stok']) }}</td>
                                    <td class="text-end text-warning">{{ $rupiah($row['beban_npk']) }}</td>
                                    <td class="text-end text-info">{{ $rupiah($row['terjual']) }}</td>
                                    <td class="text-end {{ $row['selisih_opname'] != 0 ? 'text-error' : '' }}">{{ $rupiah($row['selisih_opname']) }}</td>
                                    <td class="text-end">{{ $rupiah($row['retur']) }}</td>
                                    <td class="text-end {{ abs($row['hilang_transit']) >= 0.01 ? 'text-warning font-semibold' : 'text-base-content/40' }}">{{ $rupiah($row['hilang_transit']) }}</td>
                                    <td class="text-end {{ abs($row['tidak_terlacak']) >= 0.01 ? 'text-error font-semibold' : 'text-base-content/40' }}">{{ $rupiah($row['tidak_terlacak']) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="py-3 text-center text-base-content/50">Penerimaan ini tidak memiliki baris barang.</td></tr>
                            @endforelse
                        </tbody>
                        <tfoot>
                            <tr class="bg-base-200/40 font-bold">
                                <td colspan="2">TOTAL</td>
                                <td class="text-end">{{ $rupiah($data['total_nilai_masuk']) }}</td>
                                <td class="text-end">{{ $rupiah($data['total_sisa_stok']) }}</td>
                                <td class="text-end">{{ $rupiah($data['total_beban_npk']) }}</td>
                                <td class="text-end">{{ $rupiah($data['total_terjual']) }}</td>
                                <td class="text-end">{{ $rupiah($data['total_selisih_opname']) }}</td>
                                <td class="text-end">{{ $rupiah($data['total_retur']) }}</td>
                                <td class="text-end">{{ $rupiah($data['total_hilang_transit']) }}</td>
                                <td class="text-end">{{ $rupiah($data['total_tidak_terlacak']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                @if (abs($data['total_tidak_terlacak']) >= 0.01)
                    <div class="mx-4 mb-4 alert alert-warning">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span>
                            {{ $rupiah($data['total_tidak_terlacak']) }} belum terlacak. Nilai ini muncul ketika barang keluar lewat jalur yang tidak menyimpan kaitan ke layer asalnya. Saat ini hanya <strong>perakitan kit</strong> yang begitu: nilai komponen pindah ke layer kit dan tidak lagi terhubung ke pembelian ini. Barang yang hilang dalam perjalanan antar gudang <strong>tidak lagi masuk ke sini</strong> — nilainya berdiri sendiri di kolom Hilang di Jalan.
                        </span>
                    </div>
                @endif

                <div class="mx-4 mb-4 text-xs text-base-content/50">
                    PPN pembelian tidak masuk hitungan di atas. PPN Masukan adalah kredit pajak yang duduk di akun aset, bukan bagian dari harga pokok barang, jadi bukan salah satu ember sebaran nilai ini.
                </div>
            @endif
        </div>
    </div>
@endsection
