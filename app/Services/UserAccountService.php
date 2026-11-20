<?php

namespace App\Services;

use App\Models\LogAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class UserAccountService
{
    public const LOGIN = 'login';
    public const LOGIN_GAGAL = 'login_gagal';
    public const LOGIN_DITOLAK = 'login_ditolak';
    public const LOGOUT = 'logout';
    public const RESET_PASSWORD = 'reset_password';

    public function buat(array $data): User
    {
        $user = new User();

        $user->forceFill([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'type' => (int) $data['type'],
            'is_active' => true,
        ])->save();

        return $user;
    }

    public function perbarui(User $user, array $data): User
    {
        $atribut = [
            'name' => $data['name'],
            'email' => $data['email'],
            'type' => (int) $data['type'],
        ];

        if (!empty($data['password'])) {
            $atribut['password'] = $data['password'];
        }

        if ((int) $data['type'] !== $user->type && $user->isSuperAdmin()) {
            $this->assertBukanSuperAdminTerakhir($user);
        }

        $user->update($atribut);

        return $user;
    }

    public function nonaktifkan(User $user, User $oleh): User
    {
        if ($user->id === $oleh->id) {
            throw new RuntimeException('Anda tidak dapat menonaktifkan akun Anda sendiri.');
        }

        if (!$user->is_active) {
            throw new RuntimeException('Akun ini sudah nonaktif.');
        }

        if ($user->isSuperAdmin()) {
            $this->assertBukanSuperAdminTerakhir($user);
        }

        return DB::transaction(function () use ($user, $oleh) {
            $user->forceFill([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivated_by' => $oleh->id,
            ])->save();

            $user->tokens()->delete();

            return $user;
        });
    }

    public function aktifkanKembali(User $user): User
    {
        if ($user->is_active) {
            throw new RuntimeException('Akun ini sudah aktif.');
        }

        $user->forceFill([
            'is_active' => true,
            'deactivated_at' => null,
            'deactivated_by' => null,
        ])->save();

        return $user;
    }

    public function gantiPassword(User $user, string $passwordLama, string $passwordBaru): void
    {
        if (!Hash::check($passwordLama, $user->password)) {
            throw new RuntimeException('Password lama tidak sesuai.');
        }

        $user->update(['password' => $passwordBaru]);
    }

    public function akunNonaktifDenganKredensialBenar(string $email, string $password): ?User
    {
        $user = User::where('email', $email)->where('is_active', false)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        return $user;
    }

    public function catatLogin(User $user, ?string $ip): void
    {
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->saveQuietly();

        $this->catat(self::LOGIN, $user->id, $ip, ['email' => $user->email]);
    }

    public function catatLogout(User $user, ?string $ip): void
    {
        $this->catat(self::LOGOUT, $user->id, $ip, ['email' => $user->email]);
    }

    public function catatResetPassword(User $user, ?string $ip): void
    {
        $this->catat(self::RESET_PASSWORD, $user->id, $ip, ['email' => $user->email]);
    }

    public function catatLoginGagal(?string $email, ?string $ip, bool $akunNonaktif = false): void
    {
        $this->catat(
            $akunNonaktif ? self::LOGIN_DITOLAK : self::LOGIN_GAGAL,
            User::where('email', $email)->value('id'),
            $ip,
            ['email' => $email],
        );
    }

    private function catat(string $event, ?int $userId, ?string $ip, array $metadata): void
    {
        LogAudit::create([
            'auditable_type' => User::class,
            'auditable_id' => $userId,
            'event' => $event,
            'metadata' => $metadata + ['user_agent' => substr((string) request()?->userAgent(), 0, 255)],
            'user_id' => $userId,
            'ip_address' => $ip,
        ]);
    }

    private function assertBukanSuperAdminTerakhir(User $user): void
    {
        $lain = User::where('type', User::ROLE_SUPER_ADMIN)
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->exists();

        if (!$lain) {
            throw new RuntimeException('Super Admin aktif terakhir tidak boleh dinonaktifkan atau diturunkan perannya.');
        }
    }
}
