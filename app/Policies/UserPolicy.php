<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('identity.users.view');
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasPermissionTo('identity.users.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('identity.users.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasPermissionTo('identity.users.manage');
    }
}
