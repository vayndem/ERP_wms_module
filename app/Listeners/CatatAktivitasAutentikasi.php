<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\UserAccountService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;

class CatatAktivitasAutentikasi
{
    public function __construct(private UserAccountService $akun) {}

    public function handleLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            $this->akun->catatLogin($event->user, request()?->ip());
        }
    }

    public function handleLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            $this->akun->catatLogout($event->user, request()?->ip());
        }
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        if ($event->user instanceof User) {
            $this->akun->catatResetPassword($event->user, request()?->ip());
        }
    }

    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;
        $this->akun->catatLoginGagal($email, request()?->ip());
    }
}
