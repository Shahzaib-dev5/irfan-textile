<?php

namespace App\Modules\Core\Services;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CompanySettingsService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(array $data, User $actor, ?UploadedFile $logo = null): Company
    {
        $storedPath = $logo?->store('company/branding', 'local');

        try {
            return DB::transaction(function () use ($data, $actor, $storedPath): Company {
                $company = Company::query()->lockForUpdate()->find(1);
                $before = $company?->only(['legal_name', 'trading_name', 'ntn', 'sales_tax_registration', 'address_text', 'phone', 'email', 'functional_currency', 'timezone', 'fiscal_year_start_month']);
                $values = collect($data)->only(['legal_name', 'trading_name', 'ntn', 'sales_tax_registration', 'address_text', 'phone', 'email', 'timezone', 'fiscal_year_start_month'])->all();
                $values['functional_currency'] = 'PKR';
                $company ??= new Company(['id' => 1]);
                $company->fill($values);
                $company->id = 1;
                $company->save();
                $after = $company->fresh()->only(['legal_name', 'trading_name', 'ntn', 'sales_tax_registration', 'address_text', 'phone', 'email', 'functional_currency', 'timezone', 'fiscal_year_start_month']);
                $this->audit->record('companies', 1, $before === null ? 'create' : 'change', $before, $after);

                if ($storedPath !== null) {
                    $setting = CompanySetting::query()->where('setting_key', 'company.logo')->first();
                    $old = $setting?->value_json;
                    CompanySetting::query()->updateOrCreate(['setting_key' => 'company.logo'], ['value_json' => ['path' => $storedPath], 'updated_by' => $actor->id]);
                    $this->audit->record('company_settings', 'company.logo', 'change', $old, ['path' => $storedPath]);
                }

                return $company->fresh();
            });
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }

            throw $exception;
        }
    }
}
