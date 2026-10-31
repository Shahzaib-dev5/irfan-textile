<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class SalesPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->hasPermissionTo('sales.documents.view');
    }

    public function view(User $u, Document $d): bool
    {
        return $this->viewAny($u) && $d->type->module_code === 'sales';
    }

    public function create(User $u): bool
    {
        return $u->hasPermissionTo('sales.documents.manage');
    }
}
