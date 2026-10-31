<?php

namespace App\Modules\Parties\Services;

use App\Models\Company;
use App\Models\Party;
use App\Models\PartyAddress;
use App\Models\PartyContact;
use App\Models\PartyRole;
use App\Models\PartyRoleType;
use App\Models\PaymentTerm;
use App\Models\ProcessingJobStep;
use App\Models\ProcessingService;
use App\Models\User;
use App\Models\VendorService;
use App\Models\VendorServiceRate;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartyManagementService
{
    private const PROFILE_TABLES = ['supplier' => 'supplier_profiles', 'vendor' => 'vendor_profiles', 'customer' => 'customer_profiles'];

    /** @param array<string,mixed> $data */
    public function save(string $roleCode, array $data, ?Party $party, User $actor): Party
    {
        abort_unless(isset(self::PROFILE_TABLES[$roleCode]), 404);

        return DB::transaction(function () use ($roleCode, $data, $party, $actor): Party {
            Company::whereKey(1)->lockForUpdate()->first();
            $type = PartyRoleType::query()->where('code', $roleCode)->firstOrFail();
            if ($party) {
                $party = Party::query()->whereKey($party->getKey())->lockForUpdate()->firstOrFail();
                $before = $party->only(['code', 'legal_name', 'trading_name', 'party_kind', 'ntn', 'sales_tax_registration', 'cnic', 'email', 'phone', 'notes', 'is_active']);
                $party->fill($data['party']);
                $party->save();
                app(AuditLogger::class)->record('parties', $party->id, 'updated', $before, $party->fresh()->only(array_keys($before)));
            } else {
                $party = Party::query()->create($data['party']);
                app(AuditLogger::class)->record('parties', $party->id, 'created', null, $party->only(['code', 'legal_name', 'trading_name', 'party_kind', 'ntn', 'sales_tax_registration', 'cnic', 'email', 'phone', 'notes', 'is_active']));
            }

            $role = PartyRole::query()->where('party_id', $party->id)->where('party_role_type_id', $type->id)->lockForUpdate()->first();
            if (! $role) {
                $role = PartyRole::query()->create(['party_id' => $party->id, 'party_role_type_id' => $type->id, 'is_active' => true]);
                app(AuditLogger::class)->record('party_roles', $role->id, 'assigned', null, ['party_id' => $party->id, 'role' => $roleCode]);
            }
            $roleBefore = ['is_active' => $role->is_active];
            $role->update(['is_active' => (bool) $data['party']['is_active']]);
            if ($roleBefore['is_active'] !== $role->is_active) {
                app(AuditLogger::class)->record('party_roles', $role->id, 'status_changed', $roleBefore, ['is_active' => $role->is_active, 'party_id' => $party->id]);
            }

            $paymentTerm = PaymentTerm::query()->whereKey($data['profile']['payment_term_id'])->where('is_active', true)->firstOrFail();
            $profileData = $data['profile'];
            unset($profileData['service_ids'], $profileData['lead_time_days']);
            foreach (array_diff(['purchase_notes', 'contractor_notes', 'sales_notes', 'credit_limit', 'credit_hold'], match ($roleCode) {
                'supplier' => ['purchase_notes'], 'vendor' => ['contractor_notes'], 'customer' => ['sales_notes', 'credit_limit', 'credit_hold'],
            }) as $irrelevantField) {
                unset($profileData[$irrelevantField]);
            }
            $profileTable = self::PROFILE_TABLES[$roleCode];
            $profileBefore = DB::table($profileTable)->where('party_role_id', $role->id)->first();
            $profileAfter = [...$profileData, 'payment_term_id' => $paymentTerm->id];
            if ($roleCode === 'customer' && ! $actor->hasPermissionTo('sales.credit.manage')) {
                $oldLimit = (string) ($profileBefore->credit_limit ?? '0');
                $oldHold = (bool) ($profileBefore->credit_hold ?? false);
                abort_if(bccomp((string) ($profileData['credit_limit'] ?? '0'), $oldLimit, 4) !== 0 || (bool) ($profileData['credit_hold'] ?? false) !== $oldHold, 403, 'Credit settings require administrator permission.');
            }
            if ($profileBefore) {
                DB::table($profileTable)->where('party_role_id', $role->id)->update([...$profileAfter, 'updated_at' => now()]);
            } else {
                DB::table($profileTable)->insert(['party_role_id' => $role->id, ...$profileAfter, 'created_at' => now(), 'updated_at' => now()]);
            }
            app(AuditLogger::class)->record($profileTable, $role->id, $profileBefore ? 'updated' : 'created', $profileBefore ? (array) $profileBefore : null, $profileAfter);

            if (! ($data['_preserve_contacts_addresses'] ?? false)) {
                $this->saveContacts($party, $data['contacts'] ?? []);
                $this->saveAddresses($party, $data['addresses'] ?? []);
            }
            if ($roleCode === 'vendor') {
                $this->saveVendorServices($role, $data['profile']['service_ids'] ?? [], $data['profile']['lead_time_days'] ?? []);
            }

            return $party->fresh(['roles.type', 'contacts', 'addresses']);
        }, 3);
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function saveContacts(Party $party, array $rows): void
    {
        $kept = [];
        foreach ($rows as $row) {
            if (blank($row['name'] ?? null)) {
                continue;
            }
            $contact = isset($row['id']) ? PartyContact::query()->where('party_id', $party->id)->whereKey($row['id'])->firstOrFail() : new PartyContact(['party_id' => $party->id]);
            $before = $contact->exists ? $contact->only(['name', 'designation', 'email', 'phone', 'is_active']) : null;
            $contact->fill($row);
            $contact->is_active = true;
            $contact->save();
            app(AuditLogger::class)->record('party_contacts', $contact->id, $before ? 'updated' : 'created', $before, $contact->only(['name', 'designation', 'email', 'phone', 'is_active']));
            $kept[] = $contact->id;
        }
        PartyContact::query()->where('party_id', $party->id)->where('is_active', true)->whereNotIn('id', $kept ?: [0])->get()->each(function (PartyContact $contact): void {
            $before = $contact->only(['name', 'designation', 'email', 'phone', 'is_active']);
            $contact->update(['is_active' => false]);
            app(AuditLogger::class)->record('party_contacts', $contact->id, 'deactivated', $before, $contact->only(['name', 'designation', 'email', 'phone', 'is_active']));
        });
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function saveAddresses(Party $party, array $rows): void
    {
        $kept = [];
        foreach ($rows as $row) {
            if (blank($row['label'] ?? null)) {
                continue;
            }
            $address = isset($row['id']) ? PartyAddress::query()->where('party_id', $party->id)->whereKey($row['id'])->firstOrFail() : new PartyAddress(['party_id' => $party->id]);
            if (PartyAddress::query()->where('party_id', $party->id)->where('label', $row['label'])->where('id', '!=', $address->id ?: 0)->exists()) {
                throw ValidationException::withMessages(['addresses' => 'Address labels must be unique within this party.']);
            }
            $before = $address->exists ? $address->only(['label', 'address_type', 'address_line_1', 'address_line_2', 'city', 'province', 'postal_code', 'country_code', 'is_active']) : null;
            $address->fill($row);
            $address->is_active = true;
            $address->save();
            app(AuditLogger::class)->record('party_addresses', $address->id, $before ? 'updated' : 'created', $before, $address->only(['label', 'address_type', 'address_line_1', 'address_line_2', 'city', 'province', 'postal_code', 'country_code', 'is_active']));
            $kept[] = $address->id;
        }
        PartyAddress::query()->where('party_id', $party->id)->where('is_active', true)->whereNotIn('id', $kept ?: [0])->get()->each(function (PartyAddress $address): void {
            $before = $address->only(['label', 'address_type', 'address_line_1', 'address_line_2', 'city', 'province', 'postal_code', 'country_code', 'is_active']);
            $address->update(['is_active' => false]);
            app(AuditLogger::class)->record('party_addresses', $address->id, 'deactivated', $before, $address->only(['label', 'address_type', 'address_line_1', 'address_line_2', 'city', 'province', 'postal_code', 'country_code', 'is_active']));
        });
    }

    /** @param list<int> $serviceIds @param array<int,int|null> $leadTimes */
    private function saveVendorServices(PartyRole $role, array $serviceIds, array $leadTimes): void
    {
        if (! $role->is_active) {
            $serviceIds = [];
        }
        $serviceIds = array_map('intval', $serviceIds);
        ProcessingService::query()->whereIn('id', $serviceIds)->where('is_active', true)->get()->each(function (ProcessingService $service) use ($role, $leadTimes): void {
            $vendorService = VendorService::query()->firstOrNew(
                ['vendor_party_role_id' => $role->id, 'processing_service_id' => $service->id],
            );
            $before = $vendorService->exists ? $vendorService->only(['lead_time_days', 'is_active']) : null;
            $vendorService->fill(['lead_time_days' => $leadTimes[$service->id] ?? null, 'is_active' => true])->save();
            if ($before !== $vendorService->only(['lead_time_days', 'is_active'])) {
                app(AuditLogger::class)->record('vendor_services', $vendorService->id, $before ? 'updated' : 'assigned', $before, $vendorService->only(['lead_time_days', 'is_active', 'vendor_party_role_id', 'processing_service_id']));
            }
        });
        VendorService::query()->where('vendor_party_role_id', $role->id)->where('is_active', true)->whereNotIn('processing_service_id', $serviceIds ?: [0])->get()->each(function (VendorService $service): void {
            $before = $service->only(['lead_time_days', 'is_active']);
            $service->update(['is_active' => false]);
            app(AuditLogger::class)->record('vendor_services', $service->id, 'deactivated', $before, $service->only(['lead_time_days', 'is_active', 'vendor_party_role_id', 'processing_service_id']));
        });
    }

    /** @param array<string,mixed> $data */
    public function publishRate(VendorService $service, array $data, User $actor): VendorServiceRate
    {
        return DB::transaction(function () use ($service, $data, $actor): VendorServiceRate {
            $locked = VendorService::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $serviceDefinition = ProcessingService::query()->findOrFail($locked->processing_service_id);
            $vendorProfile = $locked->vendor()->with('role.party')->firstOrFail();
            if (! $locked->is_active || ! $serviceDefinition->is_active || ! $vendorProfile->role->is_active || ! $vendorProfile->role->party->is_active) {
                throw ValidationException::withMessages(['vendor_service' => 'Rates cannot be published for an inactive vendor capability.']);
            }
            if ((int) $serviceDefinition->billing_unit_id !== (int) $data['billing_unit_id']) {
                throw ValidationException::withMessages(['billing_unit_id' => 'The rate unit must match this service capability.']);
            }
            $from = $data['effective_from'];
            $to = $data['effective_to'] ?: null;
            $overlap = VendorServiceRate::query()->where('vendor_service_id', $locked->id)
                ->whereDate('effective_from', '<', $to ?? '9999-12-31')
                ->where(function (Builder $query) use ($from): void {
                    $query->whereNull('effective_to')->orWhereDate('effective_to', '>', $from);
                })
                ->orderByDesc('effective_from')->first();
            if ($overlap) {
                if ($overlap->effective_to === null && $overlap->effective_from->lt($from)) {
                    if (Carbon::parse($from)->lessThanOrEqualTo(today())) {
                        throw ValidationException::withMessages(['effective_from' => 'A successor to an open rate must start in the future. Historical corrections require a separate cost adjustment.']);
                    }
                    if (ProcessingJobStep::where('vendor_service_rate_id', $overlap->id)->whereDate('rate_effective_date', '>=', $from)->exists()) {
                        throw ValidationException::withMessages(['effective_from' => 'This successor would invalidate a job rate snapshot. Choose a later date.']);
                    }
                    DB::table('vendor_service_rates')->where('id', $overlap->id)->update(['effective_to' => $from]);
                    app(AuditLogger::class)->record('vendor_service_rates', $overlap->id, 'closed_prospectively', ['effective_to' => null], ['effective_to' => $from]);
                } else {
                    throw ValidationException::withMessages(['effective_from' => 'This period overlaps a published rate. Rates cannot be backdated over an existing contract.']);
                }
            }
            $rate = VendorServiceRate::query()->create([...$data, 'vendor_service_id' => $locked->id, 'currency_code' => 'PKR', 'approved_by' => $actor->id]);
            app(AuditLogger::class)->record('vendor_service_rates', $rate->id, 'published', null, $rate->only(['vendor_service_id', 'effective_from', 'effective_to', 'billing_unit_id', 'unit_rate', 'currency_code', 'contract_reference', 'rate_reason', 'approved_by']));

            return $rate;
        }, 3);
    }

    public function deactivateRole(Party $party, string $roleCode): void
    {
        DB::transaction(function () use ($party, $roleCode): void {
            $party = Party::query()->whereKey($party->id)->lockForUpdate()->firstOrFail();
            $role = $party->roles()->whereHas('type', fn (Builder $query) => $query->where('code', $roleCode))->lockForUpdate()->firstOrFail();
            $roleBefore = ['is_active' => $role->is_active];
            $role->update(['is_active' => false]);
            if ($roleCode === 'vendor') {
                VendorService::query()->where('vendor_party_role_id', $role->id)->where('is_active', true)->get()->each(function (VendorService $service): void {
                    $before = $service->only(['is_active', 'lead_time_days']);
                    $service->update(['is_active' => false]);
                    app(AuditLogger::class)->record('vendor_services', $service->id, 'deactivated', $before, $service->only(['is_active', 'lead_time_days', 'processing_service_id', 'vendor_party_role_id']));
                });
            }
            if (! $party->roles()->where('is_active', true)->exists()) {
                $party->update(['is_active' => false]);
            }
            app(AuditLogger::class)->record('party_roles', $role->id, 'deactivated', [...$roleBefore, 'role' => $roleCode, 'party_id' => $party->id], [...$roleBefore, 'role' => $roleCode, 'party_id' => $party->id, 'is_active' => false]);
        }, 3);
    }

    public function rateOn(VendorService $service, string $date): ?VendorServiceRate
    {
        return VendorServiceRate::query()->where('vendor_service_id', $service->id)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>', $date))
            ->first();
    }
}
