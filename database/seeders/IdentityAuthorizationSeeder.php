<?php

namespace Database\Seeders;

use App\Models\AccessRole;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class IdentityAuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('erp_permissions', []) as $code => $definition) {
            Permission::query()->updateOrCreate(['code' => $code], [
                'module_code' => $definition['module'],
                'description' => $definition['description'],
            ]);
        }

        $allPermissions = Permission::query()->pluck('id');
        $admin = AccessRole::query()->updateOrCreate(['code' => 'super-admin'], [
            'name' => 'System administrator',
            'description' => 'Full access to configured ERP modules.',
            'is_active' => true,
        ]);
        $admin->permissions()->sync($allPermissions);

        $identityAdmin = AccessRole::query()->updateOrCreate(['code' => 'identity-admin'], [
            'name' => 'Identity administrator',
            'description' => 'Manages business users, authorization roles and permissions.',
            'is_active' => true,
        ]);
        $identityAdmin->permissions()->sync(Permission::query()->where('module_code', 'identity')->pluck('id'));

        foreach ([
            ['code' => 'company-admin', 'name' => 'Company settings administrator', 'module' => 'core'],
            ['code' => 'calendar-admin', 'name' => 'Financial calendar administrator', 'module' => 'accounting'],
        ] as $definition) {
            $role = AccessRole::query()->updateOrCreate(['code' => $definition['code']], [
                'name' => $definition['name'], 'description' => 'Manages '.$definition['module'].' setup.', 'is_active' => true,
            ]);
            $permissions = Permission::query()->where('module_code', $definition['module']);
            if ($definition['code'] === 'calendar-admin') {
                $permissions->where('code', 'like', 'accounting.calendar.%');
            }
            $role->permissions()->sync($permissions->pluck('id'));
        }
    }
}
