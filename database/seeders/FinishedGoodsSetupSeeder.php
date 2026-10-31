<?php

namespace Database\Seeders;

use App\Models\AccessRole;
use App\Models\Permission;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Activate Finished Goods without replacing other roles' existing permissions. */
class FinishedGoodsSetupSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $admin = AccessRole::where('code', 'super-admin')->where('is_active', true)->lockForUpdate()->first();
            if (! $admin) {
                throw new LogicException('An active existing System administrator role is required before Finished Goods setup.');
            }

            $this->call(FinishedGoodsConfigurationSeeder::class);
            $ids = [];
            foreach (config('erp_permissions', []) as $code => $definition) {
                if ($definition['module'] !== 'finished-goods') {
                    continue;
                }
                $permission = Permission::updateOrCreate(['code' => $code], [
                    'module_code' => 'finished-goods', 'description' => $definition['description'],
                ]);
                $ids[] = $permission->id;
            }
            $before = $admin->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all();
            $changes = $admin->permissions()->syncWithoutDetaching($ids);
            if ($changes['attached']) {
                app(AuditLogger::class)->record('access_roles', $admin->id, 'finished_goods_permissions_granted',
                    ['permission_ids' => $before],
                    ['permission_ids' => $admin->permissions()->orderBy('permissions.id')->pluck('permissions.id')->all(), 'source' => self::class]);
            }
        }, 3);
    }
}
