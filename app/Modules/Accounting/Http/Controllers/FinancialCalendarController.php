<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Modules\Accounting\Http\Requests\StoreAccountingPeriodRequest;
use App\Modules\Accounting\Http\Requests\StoreFinancialYearRequest;
use App\Modules\Accounting\Services\FinancialCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FinancialCalendarController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', FinancialYear::class);

        return view('module.accounting::calendar.index', ['years' => FinancialYear::query()->with('periods')->orderByDesc('starts_on')->paginate(20), 'company' => Company::query()->find(1)]);
    }

    public function storeYear(StoreFinancialYearRequest $request, FinancialCalendarService $service): RedirectResponse
    {
        $service->createYear($request->validated());

        return to_route('accounting.calendar.index')->with('status', 'Financial year created. Add periods to cover its full range.');
    }

    public function storePeriod(StoreAccountingPeriodRequest $request, FinancialYear $year, FinancialCalendarService $service): RedirectResponse
    {
        $this->authorize('create', AccountingPeriod::class);
        $service->createPeriod($year, $request->validated());

        return to_route('accounting.calendar.index')->with('status', 'Accounting period created.');
    }

    public function updatePeriod(Request $request, AccountingPeriod $period, FinancialCalendarService $service): RedirectResponse
    {
        $this->authorize('update', $period);
        $data = $request->validate(['status' => ['required', 'in:open,closed']]);
        $service->changePeriodStatus($period, $data['status'] === 'closed', $request->user());

        return to_route('accounting.calendar.index')->with('status', 'Accounting period status updated.');
    }

    public function updateYear(Request $request, FinancialYear $year, FinancialCalendarService $service): RedirectResponse
    {
        $this->authorize('update', $year);
        $data = $request->validate(['is_closed' => ['required', 'boolean']]);
        $service->changeYearStatus($year, (bool) $data['is_closed'], $request->user());

        return to_route('accounting.calendar.index')->with('status', 'Financial year status updated.');
    }
}
