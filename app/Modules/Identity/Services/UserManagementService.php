<?php

namespace App\Modules\Identity\Services;

use App\Models\AccessRole;
use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param list<int> $roleIds */
    public function create(array $data, array $roleIds, User $actor): User
    {
        return DB::transaction(function () use ($data, $roleIds, $actor): User {
            $roles = $this->activeRoles($roleIds, $actor);
            $user = User::query()->create([
                'name' => $data['name'], 'email' => mb_strtolower($data['email']),
                'password' => Hash::make($data['password']), 'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
            $user->accessRoles()->sync(collect($roles)->mapWithKeys(fn (AccessRole $role) => [$role->id => ['assigned_by' => $actor->id]]));
            $this->audit->record('users', $user->id, 'create', null, $this->snapshot($user->fresh('accessRoles')));

            return $user->fresh('accessRoles');
        });
    }

    /** @param list<int> $roleIds */
    public function update(User $user, array $data, array $roleIds, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $roleIds, $actor): User {
            $roleMutex = AccessRole::query()->where('code', 'super-admin')->lockForUpdate()->first();
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $roles = $this->activeRoles($roleIds, $actor);
            $before = $this->snapshot($user->load('accessRoles'));
            $active = (bool) ($data['is_active'] ?? false);
            $passwordChanged = filled($data['password'] ?? null);
            $newRoleIds = collect($roles)->pluck('id');
            $removesAdmin = $user->hasRole('super-admin') && ! $newRoleIds->contains(fn ($id) => (int) $id === (int) AccessRole::where('code', 'super-admin')->value('id'));
            if ($user->is_active && (! $active || $removesAdmin)) {
                $this->assertNotLastActiveAdmin($user);
            }
            $user->fill(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'is_active' => $active]);
            if ($passwordChanged) {
                $user->password = Hash::make($data['password']);
            }
            $user->save();
            if (! $active || $passwordChanged) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            $user->accessRoles()->sync(collect($roles)->mapWithKeys(fn (AccessRole $role) => [$role->id => ['assigned_by' => $actor->id]]));
            $this->audit->record('users', $user->id, 'change', $before, $this->snapshot($user->fresh('accessRoles')));

            return $user->fresh('accessRoles');
        });
    }

    private function activeRoles(array $ids, User $actor): array
    {
        $roles = AccessRole::query()->with('permissions')->whereIn('id', $ids)->where('is_active', true)->lockForUpdate()->get();
        if ($roles->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['role_ids' => 'One or more selected roles are unavailable.']);
        }
        if (! $actor->hasRole('super-admin')) {
            $allowed = $actor->accessRoles()->with('permissions')->get()->flatMap->permissions->pluck('id')->map(fn ($id) => (int) $id)->unique();
            if ($roles->contains(fn (AccessRole $role) => $role->code === 'super-admin' || $role->permissions->contains(fn ($permission) => ! $allowed->contains((int) $permission->id)))) {
                throw ValidationException::withMessages(['role_ids' => 'You cannot grant a role with permissions you do not hold.']);
            }
        }

        return $roles->all();
    }

    private function assertNotLastActiveAdmin(User $excluding): void
    {
        $role = AccessRole::query()->where('code', 'super-admin')->lockForUpdate()->first();
        $count = $role?->users()->where('users.is_active', true)->where('users.id', '<>', $excluding->id)->lockForUpdate()->get()->count() ?? 0;
        if ($count < 1) {
            throw ValidationException::withMessages(['role_ids' => 'Keep at least one active system administrator.']);
        }
    }

    private function snapshot(User $user): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'is_active' => $user->is_active,
            'roles' => $user->accessRoles->pluck('code')->sort()->values()->all()];
    }
}
