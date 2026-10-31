<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class FinishedGoodsPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->hasPermissionTo('finished-goods.view');
    }

    public function view(User $u, Document $d): bool
    {
        return $this->viewAny($u) && $d->type->module_code === 'finished-goods';
    }

    public function create(User $u): bool
    {
        return $u->hasPermissionTo('finished-goods.manage');
    }
}
