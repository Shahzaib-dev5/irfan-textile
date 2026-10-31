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
use Illuminate\View\View;

class PartyManagementController extends Controller
{
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
