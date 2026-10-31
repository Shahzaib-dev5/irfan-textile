<?php

namespace App\Policies;

use App\Models\FinancialYear;
use App\Models\User;

class FinancialYearPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('accounting.calendar.view');
    }

    public function view(User $user, FinancialYear $year): bool
    {
        return $user->hasPermissionTo('accounting.calendar.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('accounting.calendar.manage');
    }

    public function update(User $user, FinancialYear $year): bool
    {
        return $user->hasPermissionTo('accounting.calendar.manage');
    }
}
