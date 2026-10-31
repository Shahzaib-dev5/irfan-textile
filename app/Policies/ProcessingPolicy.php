<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class ProcessingPolicy
{
    public function viewAny(User $u): bool
    {
        return $u->hasPermissionTo('processing.documents.view');
    }

    public function view(User $u, Document $d): bool
    {
        return $this->viewAny($u) && $d->type->module_code === 'processing';
    }

    public function create(User $u): bool
    {
        return $u->hasPermissionTo('processing.documents.manage');
    }
}
