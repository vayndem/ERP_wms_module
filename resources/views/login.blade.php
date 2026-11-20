@extends('layouts.guest')

@section('title', 'Masuk')

@section('icon')
    <svg class="h-8 w-8" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21V9l9-6 9 6v12M9 21v-6h6v6M3 21h18"/></svg>
@endsection

@section('heading')
    <h1 class="text-sm font-medium tracking-wide text-base-content/60">{{ config('app.name') }}</h1>
    <h2 class="mt-1 text-3xl font-semibold leading-tight tracking-tight">Masuk</h2>
    <p class="mx-auto mt-2 max-w-xs text-[0.9375rem] leading-relaxed text-base-content/60">Gunakan akun perusahaan untuk mengakses {{ config('app.name') }}.</p>
@endsection

@section('content')
    <form action="{{ route('login.attempt') }}" method="POST" class="flex flex-col gap-5">
        @csrf

        <div class="form-control">
            <label class="label" for="email"><span class="label-text pl-2 text-sm font-medium">Email</span></label>
            <input class="input input-bordered h-12 w-full rounded-full px-5 transition-shadow duration-200 focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/30 focus-within:border-primary focus-within:outline-none focus-within:ring-4 focus-within:ring-primary/30 motion-reduce:transition-none" type="email" name="email" id="email"
                value="{{ old('email') }}" placeholder="nama@perusahaan.com" autocomplete="email" autofocus required>
        </div>

        <div class="form-control">
            <label class="label" for="password"><span class="label-text pl-2 text-sm font-medium">Password</span></label>
            <input class="input input-bordered h-12 w-full rounded-full px-5 transition-shadow duration-200 focus:border-primary focus:outline-none focus:ring-4 focus:ring-primary/30 focus-within:border-primary focus-within:outline-none focus-within:ring-4 focus-within:ring-primary/30 motion-reduce:transition-none" type="password" name="password" id="password"
                placeholder="Masukkan password" autocomplete="current-password" required>
        </div>

        <label class="label cursor-pointer justify-start gap-2">
            <input type="checkbox" class="checkbox checkbox-sm border-base-content/40" id="remember" name="remember">
            <span class="label-text pl-2 text-sm font-medium">Ingat saya</span>
        </label>

        <div class="flex flex-col gap-2">
            <button type="submit" class="btn btn-primary h-12 w-full rounded-full text-base font-medium tracking-tight transition-transform duration-150 active:scale-[0.97] motion-reduce:transition-none motion-reduce:active:scale-100">Masuk</button>
            <a href="{{ route('password.request') }}" class="btn btn-ghost h-11 w-full rounded-full font-normal text-base-content/70">Lupa password?</a>
        </div>
    </form>
@endsection
