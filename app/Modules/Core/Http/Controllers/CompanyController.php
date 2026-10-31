<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Modules\Core\Http\Requests\UpdateCompanyRequest;
use App\Modules\Core\Services\CompanySettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CompanyController extends Controller
{
    public function edit(): View
    {
        $this->authorize('viewAny', Company::class);

        return view('module.core::company.edit', ['company' => Company::query()->find(1), 'logo' => CompanySetting::query()->where('setting_key', 'company.logo')->first()?->value_json]);
    }

    public function update(UpdateCompanyRequest $request, CompanySettingsService $service): RedirectResponse
    {
        $this->authorize('update', Company::query()->find(1) ?? new Company(['id' => 1]));
        $service->update($request->validated(), $request->user(), $request->file('logo'));

        return back()->with('status', 'Company settings saved.');
    }

    public function logo(): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermissionTo('core.company.view'), 403);
        $path = CompanySetting::query()->where('setting_key', 'company.logo')->first()?->value_json['path'] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
