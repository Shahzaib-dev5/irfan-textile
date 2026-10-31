<?php

namespace Database\Seeders;

use App\Models\AccessRole;
use App\Models\Permission;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SalesSetupSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $admin = AccessRole::where('code', 'super-admin')->where('is_active', true)->lockForUpdate()->firstOrFail();
            $this->call(SalesConfigurationSeeder::class);
            $ids = [];
            foreach (config('erp_permissions') as $code => $d) {
                if ($d['module'] === 'sales') {
                    $ids[$code] = Permission::updateOrCreate(['code' => $code], ['module_code' => 'sales', 'description' => $d['description']])->id;
                }
            }
            $wholesale = AccessRole::firstOrCreate(['code' => 'wholesale'], ['name' => 'Wholesale operator', 'description' => 'Sales and customer operations only; no production, purchasing or credit administration.', 'is_active' => true]);
            foreach ([$admin, $wholesale] as $role) {
                $allowed = $role->id === $admin->id ? $ids : array_diff_key($ids, ['sales.credit.manage' => true]);
                $changes = $role->permissions()->syncWithoutDetaching(array_values($allowed));
                if ($changes['attached']) {
                    app(AuditLogger::class)->record('access_roles', $role->id, 'sales_permissions_granted', null, ['permission_ids' => $changes['attached'], 'source' => self::class]);
                }
            }
        }, 3);
    }
}
