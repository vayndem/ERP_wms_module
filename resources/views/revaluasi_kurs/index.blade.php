@extends('layouts.app')

@section('content')
    <div class="content-page">
        <div class="mb-4">
            <h3 class="text-2xl font-bold">Revaluasi Kurs</h3>
            <p class="text-base-content/60">
                Penyesuaian pos moneter mata uang asing ke kurs penutup periode (PSAK 10). Persediaan dan aset tetap
                tidak ikut direvaluasi karena bukan pos moneter.
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

        <div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="card border border-base-300 bg-base-100 shadow-sm lg:col-span-1">
                <div class="border-b border-base-300 p-4">
                    <h4 class="font-semibold">Kurs Penutup</h4>
                </div>
                <form method="POST" action="{{ route('revaluasi-kurs.kurs') }}" class="space-y-3 p-4">
                    @csrf
                    <div class="form-control">
                        <label class="label"><span class="label-text">Periode</span></label>
                        <input type="month" name="periode" value="{{ $periode }}" required class="input input-bordered input-sm">
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Mata Uang</span></label>
                        <input type="text" name="mata_uang" maxlength="3" placeholder="USD" required
                            class="input input-bordered input-sm uppercase">
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Kurs</span></label>
                        <input type="number" step="0.0001" name="kurs" required class="input input-bordered input-sm">
                    </div>
                    <div class="form-control">
                        <label class="label"><span class="label-text">Sumber</span></label>
                        <input type="text" name="sumber" placeholder="Kurs tengah BI" class="input input-bordered input-sm">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-full">Simpan Kurs</button>
                </form>

                <div class="overflow-x-auto border-t border-base-300 p-4">
                    <table class="table table-sm">
                        <thead><tr><th>Mata Uang</th><th class="text-end">Kurs</th><th>Sumber</th></tr></thead>
                        <tbody>
                            @forelse ($kurs as $baris)
                                <tr>
                                    <td class="font-mono">{{ $baris->mata_uang }}</td>
                                    <td class="text-end">{{ number_format($baris->kurs, 2, ',', '.') }}</td>
                                    <td class="text-xs text-base-content/60">{{ $baris->sumber ?? '-' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="3" class="py-4 text-center text-base-content/50">Belum ada kurs penutup untuk periode ini.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card border border-base-300 bg-base-100 shadow-sm lg:col-span-2">
                <div class="flex flex-wrap items-end gap-3 border-b border-base-300 p-4">
                    <form method="GET" class="flex flex-wrap items-end gap-3">
                        <div class="form-control">
                            <label class="label"><span class="label-text text-xs font-semibold uppercase">Periode</span></label>
                            <input type="month" name="periode" value="{{ $periode }}" class="input input-bordered input-sm">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">Hitung</button>
                    </form>

                    @if ($hasil['siap'] && !$hasil['sudah_diposting'])
                        <form method="POST" action="{{ route('revaluasi-kurs.posting') }}"
                            onsubmit="return confirm('Posting revaluasi kurs periode {{ $periode }}?')">
                            @csrf
                            <input type="hidden" name="periode" value="{{ $periode }}">
                            <button type="submit" class="btn btn-warning btn-sm">Posting Revaluasi</button>
                        </form>
                    @endif

                    @if ($hasil['sudah_diposting'])
                        <span class="badge badge-success">Periode ini sudah diposting</span>
                    @endif
                </div>

                <div class="overflow-x-auto p-4">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Faktur</th>
                                <th>Mata Uang</th>
                                <th class="text-end">Beredar (valas)</th>
                                <th class="text-end">Kurs Lama</th>
                                <th class="text-end">Kurs Penutup</th>
                                <th class="text-end">Nilai Lama</th>
                                <th class="text-end">Nilai Baru</th>
                                <th class="text-end">Selisih</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($hasil['baris'] as $baris)
                                <tr>
                                    <td class="font-mono text-xs">{{ $baris['faktur']->no_invoice }}</td>
                                    <td>{{ $baris['mata_uang'] }}</td>
                                    <td class="text-end">{{ number_format($baris['valas_beredar'], 2, ',', '.') }}</td>
                                    <td class="text-end">{{ number_format($baris['kurs_lama'], 2, ',', '.') }}</td>
                                    <td class="text-end">
                                        {{ $baris['kurs_baru'] === null ? '-' : number_format($baris['kurs_baru'], 2, ',', '.') }}
                                    </td>
                                    <td class="text-end">{{ number_format($baris['nilai_lama'], 0, ',', '.') }}</td>
                                    <td class="text-end">
                                        {{ $baris['nilai_baru'] === null ? '-' : number_format($baris['nilai_baru'], 0, ',', '.') }}
                                    </td>
                                    <td class="text-end {{ ($baris['selisih'] ?? 0) > 0 ? 'text-error' : 'text-success' }}">
                                        {{ $baris['selisih'] === null ? $baris['catatan'] : number_format($baris['selisih'], 0, ',', '.') }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="py-6 text-center text-base-content/50">
                                        Tidak ada tagihan mata uang asing yang masih terbuka pada periode ini.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                        @if ($hasil['baris']->isNotEmpty())
                            <tfoot>
                                <tr class="font-semibold">
                                    <td colspan="7">Total selisih</td>
                                    <td class="text-end">{{ number_format($hasil['total_selisih'], 0, ',', '.') }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        </div>

        <div class="card border border-base-300 bg-base-100 shadow-sm">
            <div class="border-b border-base-300 p-4">
                <h4 class="font-semibold">Riwayat Revaluasi</h4>
            </div>
            <div class="overflow-x-auto p-4">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Periode</th>
                            <th>Faktur</th>
                            <th>Mata Uang</th>
                            <th class="text-end">Kurs Lama</th>
                            <th class="text-end">Kurs Baru</th>
                            <th class="text-end">Selisih</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($riwayat as $baris)
                            <tr>
                                <td class="font-mono text-xs">{{ $baris->periode }}</td>
                                <td class="font-mono text-xs">{{ $baris->faktur?->no_invoice ?? '-' }}</td>
                                <td>{{ $baris->mata_uang }}</td>
                                <td class="text-end">{{ number_format($baris->kurs_lama, 2, ',', '.') }}</td>
                                <td class="text-end">{{ number_format($baris->kurs_baru, 2, ',', '.') }}</td>
                                <td class="text-end {{ $baris->selisih > 0 ? 'text-error' : 'text-success' }}">
                                    {{ number_format($baris->selisih, 0, ',', '.') }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-6 text-center text-base-content/50">Belum ada revaluasi kurs yang diposting.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endsection
