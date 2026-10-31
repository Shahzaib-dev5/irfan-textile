<?php

namespace App\Policies;

use App\Models\Party;
use App\Models\User;

class PartyPolicy
{
    public function viewRole(User $user, string $role): bool
    {
        return $user->hasPermissionTo($this->permission($role, 'view'));
    }

    public function createRole(User $user, string $role): bool
    {
        return $user->hasPermissionTo($this->permission($role, 'manage'));
    }

    public function manageRole(User $user, Party $party, string $role): bool
    {
        return in_array($role, ['supplier', 'vendor', 'customer'], true)
            && $party->roles()->whereHas('type', fn ($query) => $query->where('code', $role))->exists()
            && $user->hasPermissionTo($this->permission($role, 'manage'));
    }

    private function permission(string $role, string $action): string
    {
        return match ($role) {
            'supplier' => 'purchasing.supplier.'.$action,
            'vendor' => 'processing.vendor.'.$action,
            'customer' => 'sales.customer.'.$action,
            default => 'invalid.permission',
        };
    }
}
