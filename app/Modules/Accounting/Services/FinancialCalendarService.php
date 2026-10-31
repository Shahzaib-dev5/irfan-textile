<?php

namespace App\Modules\Accounting\Services;

use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\DocumentType;
use App\Models\FinancialYear;
use App\Models\User;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinancialCalendarService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function createYear(array $data): FinancialYear
    {
        return DB::transaction(function () use ($data): FinancialYear {
            $this->lockCompany();
            if (FinancialYear::query()->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->exists()) {
                throw ValidationException::withMessages(['starts_on' => 'Financial years cannot overlap.']);
            }
            $year = FinancialYear::query()->create($data);
            foreach (DocumentType::whereIn('module_code', ['inventory', 'purchasing', 'processing'])->pluck('id') as $type) {
                DocumentSequence::firstOrCreate(['document_type_id' => $type, 'financial_year_id' => $year->id], ['next_value' => 1]);
            }
            $this->audit->record('financial_years', $year->id, 'create', null, $this->yearSnapshot($year));

            return $year;
        });
    }

    public function createPeriod(FinancialYear $year, array $data): AccountingPeriod
    {
        return DB::transaction(function () use ($year, $data): AccountingPeriod {
            $this->lockCompany();
            $year = FinancialYear::query()->lockForUpdate()->findOrFail($year->id);
            if ($year->is_closed || $data['starts_on'] < $year->starts_on->toDateString() || $data['ends_on'] > $year->ends_on->toDateString()) {
                throw ValidationException::withMessages(['starts_on' => 'The period must fall inside an open financial year.']);
            }
            $overlap = AccountingPeriod::query()->where('financial_year_id', $year->id)
                ->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['starts_on' => 'Accounting periods cannot overlap.']);
            }
            $period = $year->periods()->create($data + ['status' => 'open']);
            $this->audit->record('accounting_periods', $period->id, 'create', null, $this->periodSnapshot($period));

            return $period;
        });
    }

    public function changePeriodStatus(AccountingPeriod $period, bool $close, User $actor): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $close, $actor): AccountingPeriod {
            $this->lockCompany();
            $period = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            $year = FinancialYear::query()->lockForUpdate()->findOrFail($period->financial_year_id);
            if ($year->is_closed && ! $close) {
                throw ValidationException::withMessages(['status' => 'Reopen the financial year before reopening a period.']);
            }
            $before = $this->periodSnapshot($period);
            $period->forceFill(['status' => $close ? 'closed' : 'open', 'closed_at' => $close ? now() : null, 'closed_by' => $close ? $actor->id : null])->save();
            $this->audit->record('accounting_periods', $period->id, 'change', $before, $this->periodSnapshot($period));

            return $period;
        });
    }

    public function changeYearStatus(FinancialYear $year, bool $close, User $actor): FinancialYear
    {
        return DB::transaction(function () use ($year, $close, $actor): FinancialYear {
            $this->lockCompany();
            $year = FinancialYear::query()->lockForUpdate()->findOrFail($year->id);
            if ($close) {
                $periods = $year->periods()->orderBy('starts_on')->get();
                $covered = $periods->isNotEmpty()
                    && $periods->first()->starts_on->toDateString() === $year->starts_on->toDateString()
                    && $periods->last()->ends_on->toDateString() === $year->ends_on->toDateString()
                    && $periods->every(fn (AccountingPeriod $p) => $p->status === 'closed');
                if (! $covered) {
                    throw ValidationException::withMessages(['status' => 'Close every period and cover the complete year before closing it.']);
                }
                for ($i = 1; $i < $periods->count(); $i++) {
                    if ($periods[$i - 1]->ends_on->copy()->addDay()->toDateString() !== $periods[$i]->starts_on->toDateString()) {
                        throw ValidationException::withMessages(['status' => 'Accounting periods must cover the year without gaps before year close.']);
                    }
                }
            }
            $before = $this->yearSnapshot($year);
            $year->forceFill(['is_closed' => $close, 'closed_at' => $close ? now() : null, 'closed_by' => $close ? $actor->id : null])->save();
            $this->audit->record('financial_years', $year->id, 'change', $before, $this->yearSnapshot($year));

            return $year;
        });
    }

    private function lockCompany(): void
    {
        if (! Company::query()->lockForUpdate()->find(1)) {
            throw ValidationException::withMessages(['company' => 'Complete company setup before configuring the financial calendar.']);
        }
    }

    private function yearSnapshot(FinancialYear $year): array
    {
        return ['code' => $year->code, 'starts_on' => $year->starts_on->toDateString(), 'ends_on' => $year->ends_on->toDateString(), 'is_closed' => $year->is_closed];
    }

    private function periodSnapshot(AccountingPeriod $period): array
    {
        return ['financial_year_id' => $period->financial_year_id, 'code' => $period->code,
            'starts_on' => $period->starts_on->toDateString(), 'ends_on' => $period->ends_on->toDateString(), 'status' => $period->status];
    }
}
