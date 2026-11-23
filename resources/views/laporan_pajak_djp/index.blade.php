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

        <div class="stats stats-vertical mb-4 w-full border border-base-300 bg-base-100 shadow-sm sm:stats-horizontal">
            <div class="stat">
                <div class="stat-title">Total DPP</div>
                <div class="stat-value text-xl">Rp {{ number_format($total_dpp, 2, ',', '.') }}</div>
            </div>
            <div class="stat">
                <div class="stat-title">PPN Keluaran</div>
                <div class="stat-value text-xl">Rp {{ number_format($total_ppn, 2, ',', '.') }}</div>
            </div>
            <div class="stat">
                <div class="stat-title">PPh Dipotong</div>
                <div class="stat-value text-xl">Rp {{ number_format($total_pph, 2, ',', '.') }}</div>
            </div>
            <div class="stat {{ $faktur_belum_siap + $bupot_belum_siap > 0 ? 'bg-error/5' : '' }}">
                <div class="stat-title">Belum Siap Lapor</div>
                <div class="stat-value text-xl {{ $faktur_belum_siap + $bupot_belum_siap > 0 ? 'text-error' : '' }}">{{ $faktur_belum_siap + $bupot_belum_siap }} dokumen</div>
            </div>
        </div>

        <div class="card mb-4 border border-base-300 bg-base-100 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 p-4">
                <h4 class="font-semibold">Pemeriksaan Kepatuhan Data Pajak</h4>
                @if ($kepatuhan['total'] === 0)
                    <span class="badge badge-success badge-sm">tidak ada temuan</span>
                @else
                    <span class="badge badge-warning badge-sm">{{ $kepatuhan['total'] }} temuan</span>
                @endif
            </div>
            <div class="grid grid-cols-1 gap-2 p-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($kepatuhan['ringkasan'] as $kategori => $jumlah)
                    <div class="flex items-center justify-between rounded-lg border {{ $jumlah > 0 ? 'border-warning/40 bg-warning/5' : 'border-base-300' }} px-3 py-2 text-sm">
                        <span class="font-mono text-xs">{{ $kategori }}</span>
                        <span class="font-bold">{{ $jumlah }}</span>
                    </div>
                @endforeach
            </div>
            @if ($kepatuhan['total'] > 0)
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead><tr><th>Kategori</th><th>Dokumen</th><th>Nomor</th><th>Pihak</th><th>Keterangan</th></tr></thead>
                        <tbody>
                            @foreach ($kepatuhan['temuan'] as $temuan)
                                <tr>
                                    <td class="font-mono text-xs">{{ $temuan['kategori'] }}</td>
                                    <td>{{ $temuan['dokumen'] }}</td>
                                    <td class="font-mono text-xs">{{ $temuan['nomor'] ?: '-' }}</td>
                                    <td>{{ $temuan['pihak'] ?: '-' }}</td>
                                    <td>{{ $temuan['keterangan'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
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
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 p-4">
                <div>
                    <h4 class="font-semibold">Pemotongan PPh 23 dan PPh 4(2)</h4>
                    <p class="text-xs text-base-content/60">Dari pembayaran @include('bukti_potong._status-label', ['statusCode' => 'POSTED']) menurut tanggal pembayaran, bukan dari tanggal faktur.</p>
                </div>
                @can('viewAny', App\Models\BuktiPotong::class)
                    <a href="{{ route('bukti-potong.index', ['masa' => $masa]) }}" class="btn btn-outline btn-sm">Kelola Bukti Potong</a>
                @endcan
            </div>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Pembayaran</th><th>Invoice</th><th>Tgl Bayar</th><th>Lawan Transaksi</th><th>NPWP</th>
                            <th>Jenis</th><th class="text-end">DPP</th><th class="text-end">PPh</th><th>Bukti Potong</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($bupot as $baris)
                            <tr>
                                <td class="font-mono text-xs">{{ $baris['nomor_pembayaran'] }}</td>
                                <td class="font-mono text-xs">{{ $baris['nomor_invoice'] }}</td>
                                <td>{{ $baris['tanggal'] }}</td>
                                <td>{{ $baris['nama_lawan_transaksi'] }}</td>
                                <td class="font-mono text-xs">{{ $baris['npwp_lawan_transaksi'] ?: '-' }}</td>
                                <td>{{ $baris['jenis_pph'] }}</td>
                                <td class="text-end">Rp {{ number_format($baris['dasar_pengenaan'], 2, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format($baris['pph_dipotong'], 2, ',', '.') }}</td>
                                <td>
                                    @if ($baris['siap_lapor'])
                                        <span class="badge badge-success badge-sm">@include('bukti_potong._status-label', ['statusCode' => $baris['status_bukti']]){{ $baris['nomor_bukti_djp'] ? ' ' . $baris['nomor_bukti_djp'] : '' }}</span>
                                    @else
                                        <span class="badge badge-warning badge-sm">{{ $baris['catatan'] }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="py-6 text-center text-base-content/50">Tidak ada pembayaran yang memotong PPh pada masa pajak ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
