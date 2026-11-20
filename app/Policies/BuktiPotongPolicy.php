<?php

namespace App\Policies;

use App\Models\BuktiPotong;
use App\Models\User;

class BuktiPotongPolicy
{
    private function bolehKelola(User $user): bool
    {
        return $user->isFinance() || $user->isAccounting();
    }

    public function viewAny(User $user): bool
    {
        return $this->bolehKelola($user);
    }

    public function view(User $user, BuktiPotong $bukti): bool
    {
        return $this->bolehKelola($user);
    }

    public function create(User $user): bool
    {
        return $this->bolehKelola($user);
    }

    public function update(User $user, BuktiPotong $bukti): bool
    {
        return $this->bolehKelola($user);
    }

    public function delete(User $user, BuktiPotong $bukti): bool
    {
        return false;
    }
}
