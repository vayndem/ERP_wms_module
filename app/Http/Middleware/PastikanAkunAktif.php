<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class PastikanAkunAktif
{
    public const PESAN = 'Akun Anda dinonaktifkan. Hubungi administrator sistem.';

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $user = $guard->user();

        if ($user === null || $user->is_active !== false) {
            return $next($request);
        }

        $guard->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => self::PESAN], 401);
        }

        return redirect()->route('login')->withErrors(['email' => self::PESAN]);
    }
}
