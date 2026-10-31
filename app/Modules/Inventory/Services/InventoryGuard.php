<?php

namespace App\Modules\Inventory\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class InventoryGuard
{
    public static function businessDate(): string
    {
        return now(Company::whereKey(1)->value('timezone') ?? 'Asia/Karachi')->format('Y-m-d');
    }

    public static function actor(int $id, string $permission): User
    {
        $actor = User::findOrFail($id);
        abort_unless($actor->hasPermissionTo($permission), 403);

        return $actor;
    }

    public static function company(): void
    {
        if (! Company::whereKey(1)->lockForUpdate()->first()) {
            self::fail('Complete company setup first.');
        }
    }

    public static function fail(string $message, string $key = 'inventory'): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
