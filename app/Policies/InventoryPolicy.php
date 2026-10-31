<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class InventoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('inventory.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('inventory.manage');
    }

    public function update(User $user, Document $document): bool
    {
        return $document->status === 'draft' && $user->hasPermissionTo('inventory.manage');
    }

    public function post(User $user, Document $document): bool
    {
        return $user->hasPermissionTo('inventory.post');
    }

    public function reverse(User $user, Document $document): bool
    {
        return $user->hasPermissionTo('inventory.reverse');
    }
}
