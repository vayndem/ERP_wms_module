<?php

namespace App\Policies;

use App\Models\User;
use App\Models\KategoriAset;

class KategoriAsetPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }
    public function view(User $user, KategoriAset $category): bool
    {
        return true;
    }
    public function create(User $user): bool
    {
        return $user->isAccounting();
    }
    public function update(User $user, KategoriAset $category): bool
    {
        return $user->isAccounting();
    }
    public function delete(User $user, KategoriAset $category): bool
    {
        return $user->isAccounting() && !$category->assets()->exists();
    }
}
