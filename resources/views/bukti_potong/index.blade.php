@extends('layouts.app')

@section('content')
    <div class="content-page">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-2xl font-bold">Bukti Potong PPh 23 dan PPh 4(2)</h3>
                <p class="text-base-content/60">
                    Sumber fakta adalah pembayaran @include('bukti_potong._status-label', ['statusCode' => 'POSTED']) (tanggal pembayaran = masa pajak), bukan agregat pada faktur.
                    Nomor resmi diterbitkan DJP/Coretax dan dicatat manual di sini.
                </p>
            </div>
            @can('viewFinancialStatements')
                <a href="{{ route('laporan-pajak-djp.index', ['masa' => $masa]) }}" class="btn btn-ghost btn-sm">Data Pelaporan DJP</a>
            @endcan
        </div>

        @if (session('success'))
            <div class="alert alert-success mb-4"><i class="fa-solid fa-circle-check"></i><span>{{ session('success') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error mb-4">
                <i class="fa-solid fa-circle-xmark"></i>
                <ul class="list-inside list-disc">@foreach ($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="GET" class="card mb-4 border border-base-300 bg-base-100 p-4 shadow-sm">
            <div class="flex flex-wrap items-end gap-3">
                <div class="form-control">
                    <label class="label"><span class="label-text">Masa pajak</span></label>
                    <input type="month" name="masa" value="{{ $masa }}" class="input input-bordered input-sm">
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Status</span></label>
                    <select name="status" class="select select-bordered select-sm">
                        @foreach (['SEMUA', 'DRAFT', 'DITERBITKAN', 'DIBATALKAN', 'DIGANTI'] as $opsi)
                            <option value="{{ $opsi }}" @selected($status === $opsi)>@include('bukti_potong._status-label', ['statusCode' => $opsi])</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Tampilkan</button>
            </div>
        </form>

        <div class="card mb-4 border border-base-300 bg-base-100 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 p-4">
                <h4 class="font-semibold">Tie-out ke Buku Besar</h4>
                <span class="text-xs text-base-content/60">Bukti draf + diterbitkan vs kredit bersih akun Hutang PPh dari jurnal pembayaran</span>
            </div>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead><tr><th>Jenis</th><th class="text-end">Bukti potong</th><th class="text-end">Buku besar</th><th class="text-end">Selisih</th></tr></thead>
                    <tbody>
                        @foreach ($tieOut as $baris)
                            @if ($baris['bisa_dihitung'])
                                <tr class="{{ abs($baris['selisih']) > 0.005 ? 'bg-error/10 text-error font-semibold' : '' }}">
                                    <td>{{ \App\Models\BuktiPotong::LABEL_JENIS[$baris['jenis']] }}</td>
                                    <td class="text-end">Rp {{ number_format($baris['bukti'], 2, ',', '.') }}</td>
                                    <td class="text-end">Rp {{ number_format($baris['buku_besar'], 2, ',', '.') }}</td>
                                    <td class="text-end">Rp {{ number_format($baris['selisih'], 2, ',', '.') }}</td>
                                </tr>
                            @else
                                <tr>
                                    <td>{{ \App\Models\BuktiPotong::LABEL_JENIS[$baris['jenis']] }}</td>
                                    <td class="text-end">Rp {{ number_format($baris['bukti'], 2, ',', '.') }}</td>
                                    <td colspan="2" class="text-end text-base-content/60">tidak dapat dihitung: mapping akun Hutang PPh belum diatur</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        @if ($temuan->isNotEmpty())
            <div class="card mb-4 border border-warning/40 bg-base-100 shadow-sm">
                <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Temuan Preflight Bukti Potong ({{ $temuan->count() }})</h4></div>
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead><tr><th>Kategori</th><th>Nomor</th><th>Penerima</th><th>Keterangan</th></tr></thead>
                        <tbody>
                            @foreach ($temuan as $baris)
                                <tr>
                                    <td class="font-mono text-xs">{{ $baris['kategori'] }}</td>
                                    <td class="font-mono text-xs">{{ $baris['nomor'] ?: '-' }}</td>
                                    <td>{{ $baris['pihak'] ?: '-' }}</td>
                                    <td>{{ $baris['keterangan'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="card mb-4 border border-base-300 bg-base-100 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 p-4">
                <h4 class="font-semibold">Daftar Bukti Potong</h4>
                @can('create', App\Models\BuktiPotong::class)
                    <form method="POST" action="{{ route('bukti-potong.sinkron') }}">
                        @csrf
                        <input type="hidden" name="masa" value="{{ $masa }}">
                        <button type="submit" class="btn btn-primary btn-sm">Sinkronkan dari Pembayaran</button>
                    </form>
                @endcan
            </div>
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Ref.</th><th>Tgl Potong</th><th>Penerima</th><th>Jenis</th><th>Kode Objek</th>
                            <th class="text-end">DPP</th><th class="text-end">PPh</th><th>Pembayaran</th><th>Status</th><th>No. DJP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($daftar as $baris)
                            <tr>
                                <td><a href="{{ route('bukti-potong.show', $baris) }}" class="link link-primary font-mono text-xs">#{{ $baris->id }}</a></td>
                                <td>{{ $baris->tanggal_pemotongan->format('d-m-Y') }}</td>
                                <td>{{ $baris->penerima_nama }}</td>
                                <td>{{ $baris->label_jenis }}</td>
                                <td class="font-mono text-xs">{{ $baris->kode_objek_pajak ?: '-' }}</td>
                                <td class="text-end">Rp {{ number_format((float) $baris->dasar_pengenaan, 2, ',', '.') }}</td>
                                <td class="text-end">Rp {{ number_format((float) $baris->jumlah_pph, 2, ',', '.') }}</td>
                                <td class="font-mono text-xs">{{ $baris->pembayaran?->payment_number }}</td>
                                <td>
                                    <span class="badge badge-sm {{ match ($baris->status) { 'DITERBITKAN' => 'badge-success', 'DRAFT' => 'badge-ghost', default => 'badge-neutral' } }}">@include('bukti_potong._status-label', ['statusCode' => $baris->status])</span>
                                    @if ($baris->perlu_pembatalan)
                                        <span class="badge badge-error badge-sm">perlu dibatalkan</span>
                                    @endif
                                </td>
                                <td class="font-mono text-xs">{{ $baris->nomor_bukti_djp ?: '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="10" class="py-6 text-center text-base-content/50">Belum ada bukti potong pada masa ini. Gunakan "Sinkronkan dari Pembayaran" untuk membangkitkannya dari pembayaran yang memotong PPh.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border border-base-300 bg-base-100 shadow-sm">
            <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Ekspor</h4></div>
            <div class="grid grid-cols-1 gap-4 p-4">
                <div class="alert alert-warning">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div class="text-sm">{{ $peringatanXml }}</div>
                </div>
                @if ($penghalang->isNotEmpty())
                    <div class="alert alert-error">
                        <i class="fa-solid fa-ban"></i>
                        <div class="text-sm">Ekspor XML diblokir: {{ $penghalang->count() }} temuan preflight ({{ $penghalang->pluck('kategori')->unique()->implode(', ') }}). Bereskan dulu.</div>
                    </div>
                @endif
                <form method="GET" class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="masa" value="{{ $masa }}">
                    <div class="form-control">
                        <label class="label"><span class="label-text">Sertakan bukti berstatus</span></label>
                        <select name="status" class="select select-bordered select-sm">
                            @foreach ($pilihanStatus as $opsi)
                                <option value="{{ $opsi }}">@include('bukti_potong._status-label', ['statusCode' => $opsi]){{ $opsi === 'SEMUA' ? ' (draf dan diterbitkan)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" formaction="{{ route('bukti-potong.xml') }}" class="btn btn-outline btn-sm" @disabled($penghalang->isNotEmpty())>Unduh XML BPPU (berdasarkan contoh DJP)</button>
                    <button type="submit" formaction="{{ route('bukti-potong.excel') }}" class="btn btn-ghost btn-sm">Unduh Excel internal</button>
                </form>
            </div>
        </div>
    </div>
@endsection
