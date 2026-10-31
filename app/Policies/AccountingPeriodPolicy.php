<?php

namespace App\Policies;

use App\Models\AccountingPeriod;
use App\Models\User;

class AccountingPeriodPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('accounting.calendar.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('accounting.calendar.manage');
    }

    public function update(User $user, AccountingPeriod $period): bool
    {
        return $user->hasPermissionTo('accounting.calendar.manage');
    }
}
