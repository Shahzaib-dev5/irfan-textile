<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class PurchasingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.documents.view');
    }

    public function view(User $user, Document $document): bool
    {
        return $document->type->module_code === 'purchasing' && $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('purchasing.documents.manage');
    }

    public function update(User $user, Document $document): bool
    {
        return $document->type->module_code === 'purchasing' && $document->status === 'draft' && $this->create($user);
    }
}
