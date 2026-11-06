<?php

namespace App\Modules\Parties\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\PaymentTerm;
use App\Models\ProcessingService;
use App\Models\Unit;
use App\Models\VendorService;
use App\Modules\Parties\Http\Requests\PublishVendorRateRequest;
use App\Modules\Parties\Http\Requests\SavePartyRequest;
use App\Modules\Parties\Services\PartyManagementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PartyManagementController extends Controller
{
    public function partyIndex(Request $request): View
    {
        $user = $request->user();
        abort_unless(collect(['purchasing.supplier.view', 'processing.vendor.view', 'sales.customer.view'])->contains(fn ($permission) => $user->hasPermissionTo($permission)), 403);
        $query = Party::query()->with(['roles.type', 'roles.supplierProfile.paymentTerm', 'roles.vendorProfile.paymentTerm', 'roles.vendorProfile.services.processingService', 'roles.customerProfile.paymentTerm']);
        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(fn (Builder $q) => $q->where('legal_name', 'like', '%'.$term.'%')->orWhere('trading_name', 'like', '%'.$term.'%')->orWhere('code', 'like', '%'.$term.'%')->orWhere('phone', 'like', '%'.$term.'%'));
        }
        if ($request->query('status') === 'active') $query->where('is_active', true);
        if ($request->query('status') === 'inactive') $query->where('is_active', false);
        $visibleRoles = collect(['supplier' => 'purchasing.supplier.view', 'vendor' => 'processing.vendor.view', 'customer' => 'sales.customer.view'])->filter(fn ($permission) => $user->hasPermissionTo($permission))->keys()->all();
        $query->whereHas('roles', fn (Builder $q) => $q->whereHas('type', fn (Builder $type) => $type->whereIn('code', $visibleRoles)));
        $parties = $query->orderBy('legal_name')->paginate(20)->withQueryString();
        return view('module.parties::management.party-index', compact('parties', 'visibleRoles'));
    }

    public function partyCreate(Request $request): View
    {
        return $this->partyForm(new Party(['party_kind' => 'organization', 'is_active' => true]), $request->user());
    }

    public function partyEdit(Party $party, Request $request): View
    {
        $party->load(['roles.type', 'contacts', 'addresses', 'roles.supplierProfile', 'roles.vendorProfile.services', 'roles.customerProfile']);
        $roles = $party->roles->filter(fn ($r) => in_array($r->type->code, ['supplier','vendor','customer'], true));
        abort_unless($roles->contains(fn ($r) => $request->user()->hasPermissionTo($this->permission($r->type->code, 'view'))), 403);
        return $this->partyForm($party, $request->user());
    }

    public function partyStore(Request $request, PartyManagementService $service): RedirectResponse
    {
        $data = $request->all();
        $roles = $this->allowedSelectedRoles($request, $data['roles'] ?? []);
        abort_if($roles->isEmpty(), 422, 'Select at least one business role you are allowed to manage.');
        $validated = $this->validatePartyPayload($request, $roles, false);
        $party = null;
        DB::transaction(function () use ($roles, $validated, $service, $request, &$party): void {
            foreach ($roles as $index => $role) {
                if ($index > 0) $validated[$role]['_preserve_party_identity'] = true;
                $party = $service->save($role, $validated[$role], $party, $request->user());
            }
        }, 3);
        return to_route('parties.index')->with('status', 'Party created with selected business roles.');
    }

    public function partyUpdate(Request $request, Party $party, PartyManagementService $service): RedirectResponse
    {
        $party->load('roles.type');
        $selected = collect($request->input('roles', []))->filter(fn ($role) => in_array($role, ['supplier','vendor','customer'], true))->unique()->values();
        $existing = $party->roles->map(fn ($role) => $role->type->code)->filter(fn ($role) => in_array($role, ['supplier','vendor','customer'], true));
        abort_if($selected->isEmpty(), 422, 'A party must keep at least one business role.');
        foreach ($existing->diff($selected) as $removed) abort_unless($request->user()->hasPermissionTo($this->permission($removed, 'manage')), 403, 'You cannot remove a role you do not manage.');
        $selected = $this->allowedSelectedRoles($request, $selected->all());
        foreach ($existing->intersect($selected) as $role) {
            abort_unless($request->user()->hasPermissionTo($this->permission($role, 'manage')), 403, 'You cannot edit the '.$role.' role.');
        }
        foreach ($selected as $role) {
            abort_unless($existing->contains($role) || $request->user()->hasPermissionTo($this->permission($role, 'manage')), 403);
        }
        $validated = $this->validatePartyPayload($request, $selected, true, $party);
        DB::transaction(function () use ($selected, $existing, $validated, $service, $party, $request): void {
            foreach ($selected as $index => $role) {
                if ($index > 0) $validated[$role]['_preserve_party_identity'] = true;
                $service->save($role, $validated[$role], $party, $request->user());
            }
            foreach ($existing->diff($selected) as $removed) $service->deactivateRole($party, $removed);
        }, 3);
        return to_route('parties.index')->with('status', 'Party updated. Removed roles were deactivated and history retained.');
    }

    public function partyDeactivate(Request $request, Party $party, PartyManagementService $service): RedirectResponse
    {
        $party->load('roles.type');
        $roles = $party->roles->filter(fn ($r) => in_array($r->type->code, ['supplier','vendor','customer'], true));
        foreach ($roles as $role) abort_unless($request->user()->hasPermissionTo($this->permission($role->type->code, 'manage')), 403, 'You must be allowed to manage every role on this party to deactivate it.');
        DB::transaction(function () use ($roles, $party, $service): void {
            foreach ($roles as $role) $service->deactivateRole($party, $role->type->code);
            $party->update(['is_active' => false]);
        }, 3);
        return to_route('parties.index')->with('status', 'Party deactivated. Its transaction history was retained.');
    }

    private function allowedSelectedRoles(Request $request, array $roles)
    {
        $roles = collect($roles)->filter(fn ($r) => in_array($r, ['supplier','vendor','customer'], true))->unique()->values();
        foreach ($roles as $role) abort_unless($request->user()->hasPermissionTo($this->permission($role, 'manage')), 403, 'You cannot manage the selected '.$role.' role.');
        return $roles;
    }

    private function validatePartyPayload(Request $request, $roles, bool $editing, ?Party $party = null): array
    {
        $result = [];
        $identity = $request->input('party', []);
        $ignoreId = $party?->id;
        $validatedIdentity = validator(['party' => $identity], [
            'party.code' => ['required','string','max:40',Rule::unique('parties','code')->ignore($ignoreId)],
            'party.legal_name' => ['required','string','max:200'], 'party.trading_name' => ['nullable','string','max:200'],
            'party.party_kind' => ['required',Rule::in(['organization','individual'])], 'party.ntn' => ['nullable','string','max:30',Rule::unique('parties','ntn')->ignore($ignoreId)],
            'party.sales_tax_registration' => ['nullable','string','max:40',Rule::unique('parties','sales_tax_registration')->ignore($ignoreId)], 'party.cnic' => ['nullable','string','max:25',Rule::unique('parties','cnic')->ignore($ignoreId)],
            'party.email' => ['nullable','email','max:254'], 'party.phone' => ['nullable','string','max:40'], 'party.notes' => ['nullable','string','max:10000'], 'party.is_active' => ['required','boolean'],
        ])->validate()['party'];
        foreach ($roles as $role) {
            $form = new SavePartyRequest;
            $form->setContainer(app())->setRedirector(app('redirect'));
            $form->setRouteResolver(fn () => $request->route());
            $form->setUserResolver(fn () => $request->user());
            $profileInput = $request->input('profiles.'.$role, []);
            if ($role === 'customer' && ! $request->user()->hasPermissionTo('sales.credit.manage')) {
                $profileInput['credit_limit'] = $party?->roles()->whereHas('type', fn ($q) => $q->where('code', 'customer'))->first()?->customerProfile?->credit_limit ?? '0';
                $profileInput['credit_hold'] = $party?->roles()->whereHas('type', fn ($q) => $q->where('code', 'customer'))->first()?->customerProfile?->credit_hold ?? false;
            }
            $form->merge(['party' => $validatedIdentity, 'profile' => $profileInput, 'contacts' => $request->input('contacts', []), 'addresses' => $request->input('addresses', []), 'role' => $role]);
            if ($party) $form->route()->setParameter('party', $party);
            $roleRules = $form->rules();
            unset($roleRules['addresses.*.address_type'], $roleRules['addresses.*.address_line_1'], $roleRules['addresses.*.city'], $roleRules['addresses.*.country_code']);
            validator($form->all(), $roleRules)->validate();
            $address = trim((string) $request->input('party.address', ''));
            $addressRow = $address === '' ? [] : [[
                'id' => $party?->addresses()->where('is_active', true)->value('id'),
                'label' => 'Main address', 'address_type' => 'business', 'address_line_1' => $address,
                'city' => 'Karachi', 'country_code' => 'PK',
            ]];
            if ($addressRow && ! $addressRow[0]['id']) unset($addressRow[0]['id']);
            $profile = $profileInput;
            $profile['service_ids'] = $profile['service_ids'] ?? [];
            $result[$role] = ['party' => $validatedIdentity, 'profile' => $profile, 'contacts' => $request->input('contacts', []), 'addresses' => $addressRow ?: $request->input('addresses', [])];
            $result[$role]['_preserve_contacts_addresses'] = $role !== $roles->first();
        }
        return $result;
    }

    private function partyForm(Party $party, \App\Models\User $user): View
    {
        $party->loadMissing(['roles.type', 'roles.supplierProfile', 'roles.vendorProfile.services', 'roles.customerProfile', 'contacts', 'addresses']);
        return view('module.parties::management.party-form', [
            'party' => $party,
            'manageableRoles' => collect(['supplier' => 'purchasing.supplier.manage', 'vendor' => 'processing.vendor.manage', 'customer' => 'sales.customer.manage'])->filter(fn ($p) => $user->hasPermissionTo($p))->keys()->all(),
            'paymentTerms' => PaymentTerm::query()->where('is_active', true)->orderBy('due_days')->get(),
            'services' => ProcessingService::query()->where('is_active', true)->with('billingUnit')->orderBy('name')->get(),
        ]);
    }

    public function index(string $role, Request $request): View
    {
        abort_unless(in_array($role, ['supplier', 'vendor', 'customer'], true), 404);
        $this->authorize('viewRole', [Party::class, $role]);
        $query = Party::query()->whereHas('roles', fn (Builder $q) => $q->whereHas('type', fn (Builder $t) => $t->where('code', $role)))
            ->with(['roles.type', 'roles.supplierProfile.paymentTerm', 'roles.vendorProfile.paymentTerm', 'roles.vendorProfile.services.processingService', 'roles.customerProfile.paymentTerm']);
        if ($request->filled('q')) {
            $term = trim((string) $request->query('q'));
            $query->where(fn (Builder $q) => $q->where('legal_name', 'like', '%'.$term.'%')->orWhere('trading_name', 'like', '%'.$term.'%')->orWhere('code', 'like', '%'.$term.'%')->orWhere('phone', 'like', '%'.$term.'%'));
        }
        if ($request->query('status') === 'active') {
            $query->where('parties.is_active', true)->whereHas('roles', fn (Builder $q) => $q->where('is_active', true)->whereHas('type', fn (Builder $t) => $t->where('code', $role)));
        } elseif ($request->query('status') === 'inactive') {
            $query->where(fn (Builder $q) => $q->where('parties.is_active', false)->orWhereHas('roles', fn (Builder $r) => $r->where('is_active', false)->whereHas('type', fn (Builder $t) => $t->where('code', $role))));
        }
        $parties = $query->orderBy('legal_name')->paginate(20)->withQueryString();

        return view('module.parties::management.index', compact('parties', 'role'));
    }

    public function create(string $role): View
    {
        abort_unless(in_array($role, ['supplier', 'vendor', 'customer'], true), 404);
        $this->authorize('createRole', [Party::class, $role]);

        return $this->form($role, new Party(['party_kind' => 'organization', 'is_active' => true]), null);
    }

    public function store(SavePartyRequest $request, string $role, PartyManagementService $service): RedirectResponse
    {
        $data = $request->validated();
        $party = isset($data['existing_party_id']) ? Party::query()->findOrFail($data['existing_party_id']) : null;
        if ($party && $party->roles()->whereHas('type', fn (Builder $q) => $q->where('code', $role))->exists()) {
            abort(422, 'This party already has this business role.');
        }
        $data['_preserve_contacts_addresses'] = (bool) $party;
        unset($data['existing_party_id']);
        $service->save($role, $data, $party, $request->user());

        return to_route($role.'.index')->with('status', $party ? 'The existing party was assigned the '.$role.' role.' : ucfirst($role).' identity created.');
    }

    public function edit(Party $party, string $role): View
    {
        $this->authorize('manageRole', [$party, $role]);
        abort_unless($party->roles()->whereHas('type', fn (Builder $q) => $q->where('code', $role))->exists(), 404);
        $party->load(['roles.type', 'contacts', 'addresses']);
        $roleRow = $party->roles->first(fn (PartyRole $r) => $r->type->code === $role);
        $profile = match ($role) {
            'supplier' => $roleRow?->supplierProfile, 'vendor' => $roleRow?->vendorProfile, 'customer' => $roleRow?->customerProfile, default => null
        };

        return $this->form($role, $party, $profile, $roleRow);
    }

    public function update(SavePartyRequest $request, Party $party, string $role, PartyManagementService $service): RedirectResponse
    {
        $this->authorize('manageRole', [$party, $role]);
        abort_unless($party->roles()->whereHas('type', fn (Builder $q) => $q->where('code', $role))->exists(), 404);
        $service->save($role, $request->validated(), $party, $request->user());

        return to_route($role.'.index')->with('status', ucfirst($role).' updated.');
    }

    public function deactivate(Request $request, Party $party, string $role, PartyManagementService $service): RedirectResponse
    {
        $this->authorize('manageRole', [$party, $role]);
        abort_unless($request->user()->hasPermissionTo($this->permission($role, 'manage')), 403);
        $service->deactivateRole($party, $role);

        return to_route($role.'.index')->with('status', ucfirst($role).' role deactivated. The party history was retained.');
    }

    public function rates(string $vendorService): View
    {
        $service = VendorService::query()->with(['vendor.role.party', 'processingService.billingUnit', 'rates.billingUnit'])->findOrFail($vendorService);

        return view('module.parties::management.rates', ['vendorService' => $service, 'units' => Unit::query()->where('is_active', true)->orderBy('name')->get()]);
    }

    public function publishRate(PublishVendorRateRequest $request, string $vendorService, PartyManagementService $service): RedirectResponse
    {
        $service->publishRate(VendorService::query()->findOrFail($vendorService), $request->validated(), $request->user());

        return back()->with('status', 'Vendor rate published. Posted processing work can snapshot this historical rate.');
    }

    private function form(string $role, Party $party, mixed $profile, ?PartyRole $roleRow = null): View
    {
        $roleRow?->load(['vendorProfile.services']);

        return view('module.parties::management.form', [
            'party' => $party, 'role' => $role, 'profile' => $profile,
            'paymentTerms' => PaymentTerm::query()->where('is_active', true)->orderBy('due_days')->get(),
            'services' => ProcessingService::query()->where('is_active', true)->with('billingUnit')->orderBy('name')->get(),
            'selectedServices' => $roleRow?->vendorProfile?->services->pluck('processing_service_id')->all() ?? [],
            'contacts' => $party->exists ? $party->contacts()->get() : collect(),
            'addresses' => $party->exists ? $party->addresses()->get() : collect(),
            'eligibleParties' => $party->exists ? collect() : Party::query()->whereDoesntHave('roles', fn (Builder $q) => $q->whereHas('type', fn (Builder $t) => $t->where('code', $role)))->where('is_active', true)->orderBy('legal_name')->get(),
        ]);
    }

    private function permission(string $role, string $action): string
    {
        return match ($role) {
            'supplier' => 'purchasing.supplier.'.$action, 'vendor' => 'processing.vendor.'.$action, 'customer' => 'sales.customer.'.$action
        };
    }
}
