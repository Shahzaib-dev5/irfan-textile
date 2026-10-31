<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class FinancePolicy
{
    public function view(User $user, Document $document): bool
    {
        return $user->hasPermissionTo('accounting.finance.view') && ($document->journal()->exists() || $document->type->module_code === 'accounting');
    }
}
