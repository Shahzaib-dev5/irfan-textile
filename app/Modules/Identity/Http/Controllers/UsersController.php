<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AccessRole;
use App\Models\User;
use App\Modules\Identity\Http\Requests\StoreUserRequest;
use App\Modules\Identity\Http\Requests\UpdateUserRequest;
use App\Modules\Identity\Services\UserManagementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class UsersController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        return view('module.identity::users.index', ['users' => User::query()->with('accessRoles')->orderBy('name')->paginate(20)]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('module.identity::users.form', ['user' => new User(['is_active' => true]), 'roles' => AccessRole::query()->where('is_active', true)->orderBy('name')->get(), 'selected' => []]);
    }

    public function store(StoreUserRequest $request, UserManagementService $service): RedirectResponse
    {
        $service->create($request->validated(), $request->validated('role_ids'), $request->user());

        return to_route('identity.users.index')->with('status', 'User account created.');
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('module.identity::users.form', ['user' => $user->load('accessRoles'), 'roles' => AccessRole::query()->where('is_active', true)->orderBy('name')->get(), 'selected' => $user->accessRoles->modelKeys()]);
    }

    public function update(UpdateUserRequest $request, User $user, UserManagementService $service): RedirectResponse
    {
        $service->update($user, $request->validated(), $request->validated('role_ids'), $request->user());

        return to_route('identity.users.index')->with('status', 'User account updated.');
    }
}
