<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\SendResetLinkRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class ResetPasswordController extends Controller
{
    public function showLinkRequestForm()
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(SendResetLinkRequest $request)
    {
        $email = $request->validated()['email'];

        Password::sendResetLink(['email' => $email, 'is_active' => true]);

        return back()->with('status', 'Jika email tersebut terdaftar dan aktif, tautan reset sudah dikirim.');
    }

    public function showResetForm(string $token)
    {
        return view('auth.reset-password', ['token' => $token, 'email' => request('email')]);
    }

    public function reset(ResetPasswordRequest $request)
    {
        $status = Password::reset(
            $request->validated() + ['is_active' => true],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => __($status)])->onlyInput('email');
        }

        return redirect()->route('login')->with('status', 'Password berhasil direset. Silakan masuk dengan password baru.');
    }
}
