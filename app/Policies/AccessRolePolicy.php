<?php

namespace App\Policies;

use App\Models\AccessRole;
use App\Models\User;

class AccessRolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('identity.roles.view');
    }

    public function view(User $user, AccessRole $role): bool
    {
        return $user->hasPermissionTo('identity.roles.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('identity.roles.manage');
    }

    public function update(User $user, AccessRole $role): bool
    {
        return $user->hasPermissionTo('identity.roles.manage');
    }
}
