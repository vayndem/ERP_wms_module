@extends('layouts.guest')

@section('title', 'Lupa Password')

@section('icon-class', 'bg-warning text-warning-content')

@section('icon')
    <svg class="h-8 w-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5A1.5 1.5 0 014.5 6h15A1.5 1.5 0 0121 7.5v9a1.5 1.5 0 01-1.5 1.5h-15A1.5 1.5 0 013 16.5v-9zM3.5 7.5l8.5 6 8.5-6"/></svg>
@endsection

@section('heading')
    <h2 class="text-3xl font-semibold leading-tight tracking-tight">Lupa Password</h2>
    <p class="mx-auto mt-2 max-w-xs text-[0.9375rem] leading-relaxed text-base-content/60">
        Masukkan email akun Anda. Bila terdaftar dan masih aktif, tautan reset akan dikirim ke email tersebut.
    </p>
@endsection

@section('content')
    <form action="{{ route('password.email') }}" method="POST" class="flex flex-col gap-5">
        @csrf
        <div class="form-control">
            <label class="label" for="email"><span class="label-text pl-2 text-sm font-medium">Email</span></label>
            <input class="input input-bordered h-12 w-full rounded-full px-5 transition-shadow duration-200 focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/30 focus-within:border-primary focus-within:outline-none focus-within:ring-4 focus-within:ring-primary/30 motion-reduce:transition-none" type="email" name="email" id="email"
                value="{{ old('email') }}" placeholder="nama@perusahaan.com" autocomplete="email" autofocus required>
        </div>

        <button type="submit" class="btn btn-primary h-12 w-full rounded-full text-base font-medium tracking-tight transition-transform duration-150 active:scale-[0.97] motion-reduce:transition-none motion-reduce:active:scale-100">Kirim Tautan Reset</button>
        <a href="{{ route('login') }}" class="btn btn-ghost h-11 w-full rounded-full font-normal transition-transform duration-150 active:scale-[0.97] motion-reduce:transition-none motion-reduce:active:scale-100">Kembali ke halaman masuk</a>
    </form>
@endsection
