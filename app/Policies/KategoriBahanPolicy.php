<?php

namespace App\Policies;

use App\Models\KategoriBahan;
use App\Models\User;

class KategoriBahanPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAccounting();
    }

    public function view(User $user, KategoriBahan $kategoriBahan): bool
    {
        return $user->isAccounting();
    }

    public function create(User $user): bool
    {
        return $user->isAccounting();
    }

    public function update(User $user, KategoriBahan $kategoriBahan): bool
    {
        return $user->isAccounting();
    }

    public function delete(User $user, KategoriBahan $kategoriBahan): bool
    {
        return $user->isAccounting()
            && !$kategoriBahan->bahan()->exists()
            && !$kategoriBahan->bahanByType()->exists()
            && !$kategoriBahan->lpbDetails()->exists();
    }
}
