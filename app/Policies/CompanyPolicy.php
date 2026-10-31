<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('core.company.view');
    }

    public function update(User $user, Company $company): bool
    {
        return $user->hasPermissionTo('core.company.manage');
    }
}
