@extends('layouts.app')

@section('content')
    <div class="content-page">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-2xl font-bold">Bukti Potong #{{ $bukti->id }} (versi {{ $bukti->versi }})</h3>
                <p class="text-base-content/60">{{ $bukti->label_jenis }} &middot; masa {{ $bukti->masa_pajak }}/{{ $bukti->tahun_pajak }}</p>
            </div>
            <a href="{{ route('bukti-potong.index', ['masa' => sprintf('%04d-%02d', $bukti->tahun_pajak, $bukti->masa_pajak)]) }}" class="btn btn-ghost btn-sm">Kembali ke daftar</a>
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
        @if ($bukti->perlu_pembatalan)
            <div class="alert alert-error mb-4"><i class="fa-solid fa-ban"></i><span>Pembayaran sumber sudah dibatalkan (VOID). Bukti terbit ini perlu dibatalkan di Coretax dan di sini.</span></div>
        @endif
        @if ($bukti->jenis_pph === 'PPH23' && !$npwpValid)
            <div class="alert alert-warning mb-4"><i class="fa-solid fa-triangle-exclamation"></i><span>Penerima tanpa NPWP valid. Pasal 23 ayat (1a) UU PPh menyebut tarif lebih tinggi 100%; sistem tidak mengubah potongan. Keputusan produk masih terbuka.</span></div>
        @endif

        <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div class="card border border-base-300 bg-base-100 shadow-sm">
                <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Pemotongan</h4></div>
                <dl class="grid grid-cols-1 gap-2 p-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-base-content/60">Status</dt><dd><span class="badge badge-sm {{ $bukti->isDiterbitkan() ? 'badge-success' : 'badge-ghost' }}">{{ $bukti->status }}</span></dd></div>
                    <div><dt class="text-base-content/60">Nomor bukti DJP</dt><dd class="font-mono">{{ $bukti->nomor_bukti_djp ?: '-' }}</dd></div>
                    <div><dt class="text-base-content/60">Tanggal pemotongan</dt><dd>{{ $bukti->tanggal_pemotongan->format('d-m-Y') }}</dd></div>
                    <div><dt class="text-base-content/60">Tanggal diterbitkan</dt><dd>{{ $bukti->tanggal_diterbitkan?->format('d-m-Y') ?: '-' }}</dd></div>
                    <div><dt class="text-base-content/60">Kode objek pajak</dt><dd class="font-mono">{{ $bukti->kode_objek_pajak ?: '-' }}</dd></div>
                    <div><dt class="text-base-content/60">Tarif</dt><dd>{{ rtrim(rtrim(number_format((float) $bukti->tarif, 4, ',', '.'), '0'), ',') }}%</dd></div>
                    <div><dt class="text-base-content/60">Dasar pengenaan</dt><dd>Rp {{ number_format((float) $bukti->dasar_pengenaan, 2, ',', '.') }}</dd></div>
                    <div><dt class="text-base-content/60">PPh dipotong</dt><dd class="font-semibold">Rp {{ number_format((float) $bukti->jumlah_pph, 2, ',', '.') }}</dd></div>
                    <div><dt class="text-base-content/60">Pembayaran</dt><dd class="font-mono">{{ $bukti->pembayaran?->payment_number }} ({{ $bukti->pembayaran?->status }})</dd></div>
                    <div><dt class="text-base-content/60">Invoice</dt><dd class="font-mono">{{ $bukti->pembayaran?->invoice?->no_invoice }}</dd></div>
                    @if ($bukti->menggantikan)
                        <div><dt class="text-base-content/60">Menggantikan</dt><dd><a class="link link-primary" href="{{ route('bukti-potong.show', $bukti->menggantikan) }}">#{{ $bukti->menggantikan->id }}</a></dd></div>
                    @endif
                    @if ($bukti->penggantinya)
                        <div><dt class="text-base-content/60">Digantikan oleh</dt><dd><a class="link link-primary" href="{{ route('bukti-potong.show', $bukti->penggantinya) }}">#{{ $bukti->penggantinya->id }}</a></dd></div>
                    @endif
                    @if ($bukti->catatan)
                        <div class="sm:col-span-2"><dt class="text-base-content/60">Catatan</dt><dd>{{ $bukti->catatan }}</dd></div>
                    @endif
                </dl>
            </div>

            <div class="card border border-base-300 bg-base-100 shadow-sm">
                <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Penerima Penghasilan (snapshot)</h4></div>
                <dl class="grid grid-cols-1 gap-2 p-4 text-sm sm:grid-cols-2">
                    <div class="sm:col-span-2"><dt class="text-base-content/60">Nama</dt><dd>{{ $bukti->penerima_nama }}</dd></div>
                    <div><dt class="text-base-content/60">NPWP</dt><dd class="font-mono">{{ $bukti->penerima_npwp ?: '-' }}</dd></div>
                    <div><dt class="text-base-content/60">NIK</dt><dd class="font-mono">{{ $bukti->penerima_nik ?: '-' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-base-content/60">NITKU</dt><dd class="font-mono">{{ $bukti->penerima_nitku ?: '-' }}</dd></div>
                    <div class="sm:col-span-2"><dt class="text-base-content/60">Alamat</dt><dd>{{ $bukti->penerima_alamat ?: '-' }}</dd></div>
                </dl>
                <p class="px-4 pb-4 text-xs text-base-content/60">Draft mengikuti master supplier sampai diterbitkan; setelah DITERBITKAN snapshot ini tidak berubah.</p>
            </div>
        </div>

        @can('update', $bukti)
            @if ($bukti->isDraft())
                <div class="card mb-4 border border-base-300 bg-base-100 shadow-sm">
                    <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Terbitkan</h4></div>
                    <div class="p-4">
                        @if ($masalah !== [])
                            <div class="alert alert-warning mb-3">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <ul class="list-inside list-disc text-sm">@foreach ($masalah as $pesan)<li>{{ $pesan }}</li>@endforeach</ul>
                            </div>
                        @endif
                        <form method="POST" action="{{ route('bukti-potong.terbitkan', $bukti) }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <div class="form-control">
                                <label class="label"><span class="label-text">Nomor bukti potong dari Coretax</span></label>
                                <input type="text" name="nomor_bukti_djp" value="{{ old('nomor_bukti_djp') }}" maxlength="60" required class="input input-bordered input-sm w-full sm:w-72">
                            </div>
                            <div class="form-control">
                                <label class="label"><span class="label-text">Tanggal diterbitkan</span></label>
                                <input type="date" name="tanggal_diterbitkan" value="{{ old('tanggal_diterbitkan', today()->toDateString()) }}" class="input input-bordered input-sm">
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm">Catat sebagai DITERBITKAN</button>
                        </form>
                        <p class="mt-2 text-xs text-base-content/60">Nomor resmi diterbitkan DJP/Coretax. Sistem ini tidak membuat nomor sendiri.</p>
                    </div>
                </div>
            @endif

            @if ($bukti->isDraft() || $bukti->isDiterbitkan())
                <div class="card mb-4 border border-base-300 bg-base-100 shadow-sm">
                    <div class="border-b border-base-300 p-4"><h4 class="font-semibold">Koreksi</h4></div>
                    <div class="flex flex-wrap items-end gap-3 p-4">
                        <form method="POST" action="{{ route('bukti-potong.batalkan', $bukti) }}" class="flex flex-wrap items-end gap-3">
                            @csrf
                            <div class="form-control">
                                <label class="label"><span class="label-text">Alasan pembatalan</span></label>
                                <input type="text" name="alasan" required minlength="5" maxlength="300" class="input input-bordered input-sm w-full sm:w-72">
                            </div>
                            <button type="submit" class="btn btn-error btn-outline btn-sm">Batalkan</button>
                        </form>
                        @if ($bukti->isDiterbitkan())
                            <form method="POST" action="{{ route('bukti-potong.pembetulan', $bukti) }}">
                                @csrf
                                <button type="submit" class="btn btn-outline btn-sm">Buat draft pembetulan</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif
        @endcan

        <div class="mt-4">
            @include('layouts.template.lampiran-panel', [
                'induk' => $bukti,
                'tipe' => 'bukti-potong',
                'kategori' => ['BUKTI_POTONG', 'LAIN'],
            ])
        </div>
    </div>
@endsection
