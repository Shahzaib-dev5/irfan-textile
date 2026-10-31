<?php

namespace Database\Seeders;

use App\Models\AccessRole;
use App\Models\Permission;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FinanceSetupSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $role = AccessRole::where('code', 'super-admin')->where('is_active', true)->lockForUpdate()->firstOrFail();
            $this->call(FinanceConfigurationSeeder::class);
            $ids = [];
            foreach (config('erp_permissions') as $code => $definition) {
                if ($definition['module'] === 'accounting') {
                    $ids[] = Permission::updateOrCreate(['code' => $code], ['module_code' => 'accounting', 'description' => $definition['description']])->id;
                }
            }
            $changes = $role->permissions()->syncWithoutDetaching($ids);
            if ($changes['attached']) {
                app(AuditLogger::class)->record('access_roles', $role->id, 'finance_permissions_granted', null, ['permission_ids' => $changes['attached']]);
            }
        }, 3);
    }
}
