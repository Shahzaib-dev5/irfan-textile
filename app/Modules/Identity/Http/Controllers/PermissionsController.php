<?php

namespace App\Modules\Identity\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\View\View;

class PermissionsController extends Controller
{
    public function index(): View
    {
        abort_unless(auth()->user()->hasPermissionTo('identity.permissions.view'), 403);

        return view('module.identity::permissions.index', ['permissions' => Permission::query()->withCount('roles')->orderBy('module_code')->orderBy('code')->get()->groupBy('module_code')]);
    }
}
