<?php

namespace App\Modules\Identity\Services;

use App\Models\AccessRole;
use App\Models\Permission;
use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccessRoleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(array $data, User $actor): AccessRole
    {
        return DB::transaction(function () use ($data, $actor): AccessRole {
            $role = AccessRole::query()->create([
                'code' => str($data['name'])->slug('-')->limit(45, '')->toString().'-'.str()->lower(str()->random(4)),
                'name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => (bool) $data['is_active'],
            ]);
            $this->syncPermissions($role, $data['permission_ids'] ?? [], $actor);
            $this->audit->record('access_roles', $role->id, 'create', null, $this->snapshot($role->fresh('permissions')));

            return $role->fresh('permissions');
        });
    }

    public function update(AccessRole $role, array $data, User $actor): AccessRole
    {
        return DB::transaction(function () use ($role, $data, $actor): AccessRole {
            $role = AccessRole::query()->lockForUpdate()->findOrFail($role->id);
            if ($role->code === 'super-admin' && ! ($data['is_active'] ?? false)) {
                throw ValidationException::withMessages(['is_active' => 'The system administrator role cannot be deactivated.']);
            }
            if ($role->code === 'super-admin' && ! $actor->hasRole('super-admin')) {
                throw ValidationException::withMessages(['role' => 'Only a system administrator can change the protected administrator role.']);
            }
            $before = $this->snapshot($role->load('permissions'));
            $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => (bool) ($data['is_active'] ?? false)])->save();
            $this->syncPermissions($role, $data['permission_ids'] ?? [], $actor);
            $this->audit->record('access_roles', $role->id, 'change', $before, $this->snapshot($role->fresh('permissions')));

            return $role->fresh('permissions');
        });
    }

    private function syncPermissions(AccessRole $role, array $ids, User $actor): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (Permission::query()->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages(['permission_ids' => 'One or more permissions are invalid.']);
        }
        if ($role->code === 'super-admin' && count($ids) !== Permission::query()->count()) {
            throw ValidationException::withMessages(['permission_ids' => 'The system administrator role must retain every registered permission.']);
        }
        if (! $actor->hasRole('super-admin')) {
            $allowed = $actor->accessRoles()->with('permissions')->get()->flatMap->permissions->pluck('id')->map(fn ($id) => (int) $id)->unique();
            if (collect($ids)->contains(fn ($id) => ! $allowed->contains((int) $id))) {
                throw ValidationException::withMessages(['permission_ids' => 'You cannot grant permissions you do not hold.']);
            }
        }
        $role->permissions()->sync($ids);
    }

    private function snapshot(AccessRole $role): array
    {
        return ['code' => $role->code, 'name' => $role->name, 'description' => $role->description,
            'is_active' => $role->is_active, 'permissions' => $role->permissions->pluck('code')->sort()->values()->all()];
    }
}
