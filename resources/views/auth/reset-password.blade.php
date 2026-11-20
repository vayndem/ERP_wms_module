@extends('layouts.guest')

@section('title', 'Reset Password')

@section('icon')
    <svg class="h-8 w-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V7.5a4.5 4.5 0 00-9 0M6 10.5h12a1.5 1.5 0 011.5 1.5v7.5A1.5 1.5 0 0118 21H6a1.5 1.5 0 01-1.5-1.5V12A1.5 1.5 0 016 10.5z"/></svg>
@endsection

@section('heading')
    <h2 class="text-3xl font-semibold leading-tight tracking-tight">Atur Password Baru</h2>
    <p class="mx-auto mt-2 max-w-xs text-[0.9375rem] leading-relaxed text-base-content/60">Minimal 8 karakter, memuat huruf dan angka.</p>
@endsection

@section('content')
    <form action="{{ route('password.update') }}" method="POST" class="flex flex-col gap-5">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="form-control">
            <label class="label" for="email"><span class="label-text pl-2 text-sm font-medium">Email</span></label>
            <input class="input input-bordered h-12 w-full rounded-full px-5 transition-shadow duration-200 focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/30 focus-within:border-primary focus-within:outline-none focus-within:ring-4 focus-within:ring-primary/30 motion-reduce:transition-none" type="email" name="email" id="email"
                value="{{ old('email', $email) }}" autocomplete="email" required>
        </div>

        <div class="form-control">
            <label class="label" for="password"><span class="label-text pl-2 text-sm font-medium">Password Baru</span></label>
            <input class="input input-bordered h-12 w-full rounded-full px-5 transition-shadow duration-200 focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/30 focus-within:border-primary focus-within:outline-none focus-within:ring-4 focus-within:ring-primary/30 motion-reduce:transition-none" type="password" name="password" id="password"
                autocomplete="new-password" required autofocus>
        </div>

        <div class="form-control">
            <label class="label" for="password_confirmation"><span class="label-text pl-2 text-sm font-medium">Ulangi Password Baru</span></label>
            <input class="input input-bordered h-12 w-full rounded-full px-5 transition-shadow duration-200 focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/30 focus-within:border-primary focus-within:outline-none focus-within:ring-4 focus-within:ring-primary/30 motion-reduce:transition-none" type="password" name="password_confirmation"
                id="password_confirmation" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary h-12 w-full rounded-full text-base font-medium tracking-tight transition-transform duration-150 active:scale-[0.97] motion-reduce:transition-none motion-reduce:active:scale-100">Simpan Password Baru</button>
        <a href="{{ route('login') }}" class="btn btn-ghost h-11 w-full rounded-full font-normal transition-transform duration-150 active:scale-[0.97] motion-reduce:transition-none motion-reduce:active:scale-100">Kembali ke halaman masuk</a>
    </form>
@endsection
