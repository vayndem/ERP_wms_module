<?php

namespace App\Policies;

use App\Models\BaganAkun;
use App\Models\User;

class BaganAkunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAccounting();
    }

    public function view(User $user, BaganAkun $chartOfAccount): bool
    {
        return $user->isAccounting();
    }

    public function create(User $user): bool
    {
        return $user->isAccounting();
    }

    public function update(User $user, BaganAkun $chartOfAccount): bool
    {
        return $user->isAccounting();
    }

    public function delete(User $user, BaganAkun $chartOfAccount): bool
    {
        return $user->isAccounting()
            && !$chartOfAccount->jurnalDetails()->exists()
            && !$chartOfAccount->isMapped();
    }

    public function updateMapping(User $user): bool
    {
        return $user->isAccounting();
    }
}
