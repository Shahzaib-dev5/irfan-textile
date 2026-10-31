<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class ProductionPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->hasPermissionTo('production.view');
    }

    public function view(User $u, Document $d): bool
    {
        return $this->viewAny($u) && $d->type->module_code === 'production';
    }

    public function create(User $u): bool
    {
        return $u->hasPermissionTo('production.manage');
    }
}
