@extends('layouts.app')

@section('content')
    <div class="content-page">
        <h3 class="mb-4 text-2xl font-bold">{{ $pelanggan->exists ? 'Ubah' : 'Tambah' }} Pelanggan</h3>
        @if (session('success'))
            <div class="alert alert-success mb-4"><i class="fa-solid fa-circle-check"></i><span>{{ session('success') }}</span></div>
        @endif
        @if ($errors->any())
            <div class="alert alert-error mb-4">
                <i class="fa-solid fa-circle-exclamation"></i>
                <ul class="list-inside list-disc">@foreach ($errors->all() as $pesan)<li>{{ $pesan }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ $pelanggan->exists ? route('pelanggan.update', $pelanggan) : route('pelanggan.store') }}" class="card border border-base-300 bg-base-100 p-4 shadow-sm">
            @csrf
            @if ($pelanggan->exists) @method('PUT') @endif

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div class="form-control">
                    <label class="label"><span class="label-text">Kode</span></label>
                    <input type="text" name="kode" value="{{ old('kode', $pelanggan->kode) }}" required class="input input-bordered input-sm">
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Nama</span></label>
                    <input type="text" name="nama" value="{{ old('nama', $pelanggan->nama) }}" required class="input input-bordered input-sm">
                </div>
                @include('partials.identitas-pajak', ['model' => $pelanggan])
                <div class="form-control">
                    <label class="label"><span class="label-text">Telepon</span></label>
                    <input type="text" name="telp" value="{{ old('telp', $pelanggan->telp) }}" class="input input-bordered input-sm">
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Email</span></label>
                    <input type="email" name="email" value="{{ old('email', $pelanggan->email) }}" class="input input-bordered input-sm">
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Untuk Perhatian</span></label>
                    <input type="text" name="up" value="{{ old('up', $pelanggan->up) }}" class="input input-bordered input-sm">
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Termin (hari)</span></label>
                    <input type="number" name="termin_hari" min="0" max="365" value="{{ old('termin_hari', $pelanggan->termin_hari ?? 30) }}" required class="input input-bordered input-sm">
                </div>
                <div class="form-control">
                    <label class="label"><span class="label-text">Plafon Kredit</span></label>
                    <input type="number" step="0.01" min="0" name="plafon_kredit" value="{{ old('plafon_kredit', $pelanggan->plafon_kredit ?? 0) }}" class="input input-bordered input-sm">
                </div>
                <div class="form-control md:col-span-2">
                    <label class="label"><span class="label-text">Alamat</span></label>
                    <textarea name="alamat" rows="3" class="textarea textarea-bordered">{{ old('alamat', $pelanggan->alamat) }}</textarea>
                </div>
                <div class="form-control">
                    <label class="label cursor-pointer justify-start gap-3">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $pelanggan->is_active ?? true)) class="checkbox checkbox-sm">
                        <span class="label-text">Aktif</span>
                    </label>
                </div>
            </div>

            <div class="mt-4 flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">Simpan</button>
                <a href="{{ route('pelanggan.index') }}" class="btn btn-ghost btn-sm">Batal</a>
            </div>
        </form>
    </div>
@endsection
