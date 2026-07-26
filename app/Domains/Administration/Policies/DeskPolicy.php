<?php

namespace App\Domains\Administration\Policies;

use App\Domains\Administration\Enums\CmopRole;
use App\Domains\Administration\Models\Desk;
use App\Models\User;

class DeskPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function update(User $user, Desk $desk): bool
    {
        return $user->hasRole(CmopRole::Admin->value);
    }
}
