@extends('layouts.app')

@section('content')
    <div class="content-page">
        <div class="mb-4">
            <h3 class="text-2xl font-bold">Data Pelaporan Pajak (Coretax DJP)</h3>
            <p class="text-base-content/60">
                Faktur pajak keluaran dan bukti potong unifikasi untuk satu masa pajak, siap dipetakan ke template impor Coretax.
            </p>
        </div>

        <div class="alert alert-warning mb-4">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <div class="font-semibold">Verifikasi layout sebelum dipakai melapor.</div>
                <div class="text-sm">
                    Sejak 1 Januari 2025 Coretax hanya menerima <strong>XML</strong>; CSV e-Faktur lama sudah tidak dipakai.
                    Nama elemen XML di halaman ini disusun dari data yang DJP butuhkan, bukan disalin dari berkas resmi.
                    Cocokkan dulu dengan <strong>{{ $templateAcuan }}</strong> dan <strong>{{ $converterAcuan }}</strong>
                    dari laman Template XML dan Converter DJP sebelum file ini diunggah.
                </div>
            </div>
        </div>

        <form method="GET" class="card mb-4 border border-base-300 bg-base-100 p-4 shadow-sm">
            <div class="flex flex-wrap items-end gap-3">
                <div class="form-control">
                    <label class="label"><span class="label-text">Masa pajak</span></label>
                    <input type="month" name="masa" value="{{ $masa }}" class="input input-bordered input-sm">
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Tampilkan</button>
                <a href="{{ route('laporan-pajak-djp.xml', ['masa' => $masa]) }}" class="btn btn-outline btn-sm">Unduh XML Faktur Keluaran</a>
            </div>
        </form>

        <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 md:grid-cols-4">
            <div class="rounded-lg border border-base-300 bg-base-100 p-4">
                <p class="text-xs uppercase text-base-content/60">Total DPP</p>
                <p class="text-xl font-bold">Rp {{ number_format($total_dpp, 2, ',', '.') }}</p>
            </div>
            <div class="rounded-lg border border-base-300 bg-base-100 p-4">
                <p class="text-xs uppercase text-base-content/60">PPN Keluaran</p>
                <p class="text-xl font-bold">Rp {{ number_format($total_ppn, 2, ',', '.') }}</p>
            </div>
            <div class="rounded-lg border border-base-300 bg-base-100 p-4">
                <p class="text-xs uppercase text-base-content/60">PPh Dipotong</p>
                <p class="text-xl font-bold">Rp {{ number_format($total_pph, 2, ',', '.') }}</p>
            </div>
            <div class="rounded-lg border {{ $faktur_belum_siap + $bupot_belum_siap > 0 ? 'border-error/40 bg-error/5' : 'border-base-300 bg-base-100' }} p-4">
                <p class="text-xs uppercase text-base-content/60">Belum Siap Lapor</p>
                <p class="text-xl font-bold">{{ $faktur_belum_siap + $bupot_belum_siap }} dokumen</p>
            </div>
        </div>

        <div class="card mb-4 border border-base-300 bg-base-100 shadow-sm">
            <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Faktur Pajak Keluaran</h4></div>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>NSFP</th><th>Tanggal</th><th>Pembeli</th><th>NPWP</th>
                            <th class="text-end">DPP</th><th class="text-end">PPN</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($faktur as $baris)
                            <tr>
                                <td class="font-mono text-xs">{{ $baris['nomor_faktur_pajak'] ?: '-' }}</td>
                                <td>{{ $baris['tanggal'] }}</td>
                                <td>{{ $baris['nama_pembeli'] }}</td>
                                <td class="font-mono text-xs">{{ $baris['npwp_pembeli'] ?: '-' }}</td>
                                <td class="text-end">Rp {{ number_format($baris['dpp'], 2, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($baris['ppn'], 2, ',', '.') }}</td>
                                <td>
                                    @if ($baris['siap_lapor'])
                                        <span class="badge badge-success badge-sm">siap</span>
                                    @else
                                        <span class="badge badge-warning badge-sm">{{ $baris['catatan'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="py-6 text-center text-base-content/50">Tidak ada faktur penjualan terposting pada masa pajak ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border border-base-300 bg-base-100 shadow-sm">
            <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Bukti Potong Unifikasi</h4></div>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Invoice</th><th>Tanggal</th><th>Lawan Transaksi</th><th>NPWP</th>
                            <th>Jenis</th><th class="text-end">DPP</th><th class="text-end">PPh</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($bupot as $baris)
                            <tr>
                                <td class="font-mono text-xs">{{ $baris['nomor_invoice'] }}</td>
                                <td>{{ $baris['tanggal'] }}</td>
                                <td>{{ $baris['nama_lawan_transaksi'] }}</td>
                                <td class="font-mono text-xs">{{ $baris['npwp_lawan_transaksi'] ?: '-' }}</td>
                                <td>{{ $baris['jenis_pph'] }}</td>
                                <td class="text-end">Rp {{ number_format($baris['dasar_pengenaan'], 2, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($baris['pph_dipotong'], 2, ',', '.') }}</td>
                                <td>
                                    @if ($baris['siap_lapor'])
                                        <span class="badge badge-success badge-sm">siap</span>
                                    @else
                                        <span class="badge badge-warning badge-sm">{{ $baris['catatan'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="py-6 text-center text-base-content/50">Tidak ada pemotongan PPh pada masa pajak ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
