<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AccessRole;
use App\Models\Permission;
use App\Modules\Identity\Http\Requests\StoreAccessRoleRequest;
use App\Modules\Identity\Http\Requests\UpdateAccessRoleRequest;
use App\Modules\Identity\Services\AccessRoleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class AccessRolesController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', AccessRole::class);

        return view('module.identity::roles.index', ['roles' => AccessRole::query()->withCount(['users', 'permissions'])->orderBy('name')->paginate(20)]);
    }

    public function create(): View
    {
        $this->authorize('create', AccessRole::class);

        return $this->form(new AccessRole(['is_active' => true]), collect(), 'Create role');
    }

    public function store(StoreAccessRoleRequest $request, AccessRoleService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->user());

        return to_route('identity.roles.index')->with('status', 'Authorization role created.');
    }

    public function edit(AccessRole $role): View
    {
        $this->authorize('update', $role);

        return $this->form($role->load('permissions'), $role->permissions, 'Edit role');
    }

    public function update(UpdateAccessRoleRequest $request, AccessRole $role, AccessRoleService $service): RedirectResponse
    {
        $service->update($role, $request->validated(), $request->user());

        return to_route('identity.roles.index')->with('status', 'Authorization role updated.');
    }

    private function form(AccessRole $role, $selectedPermissions, string $title): View
    {
        return view('module.identity::roles.form', ['role' => $role, 'title' => $title,
            'permissions' => Permission::query()->orderBy('module_code')->orderBy('code')->get()->groupBy('module_code'),
            'selected' => $selectedPermissions->pluck('id')->all()]);
    }
}
