<?php

namespace App\Policies;

use App\Models\User;

class WarehousePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('warehouses.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('warehouses.manage');
    }

    public function update(User $user): bool
    {
        return $this->create($user);
    }
}
