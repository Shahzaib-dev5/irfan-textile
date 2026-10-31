<?php

namespace App\Modules\Parties\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePartyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->route('party') ? 'manage' : 'manage';
        $role = $this->route('role');
        $module = match ($role) {
            'supplier' => 'purchasing', 'vendor' => 'processing', 'customer' => 'sales', default => 'parties'
        };

        return (bool) $this->user()?->hasPermissionTo($module.'.'.$role.'.'.$permission);
    }

    public function rules(): array
    {
        $party = $this->route('party');
        $ignoreId = $party?->id ?? $this->input('existing_party_id');
        $role = $this->route('role');
        $rules = [
            'existing_party_id' => ['nullable', 'integer', 'exists:parties,id'],
            'party.code' => ['required', 'string', 'max:40', Rule::unique('parties', 'code')->ignore($ignoreId)],
            'party.legal_name' => ['required', 'string', 'max:200'],
            'party.trading_name' => ['nullable', 'string', 'max:200'],
            'party.party_kind' => ['required', Rule::in(['organization', 'individual'])],
            'party.ntn' => ['nullable', 'string', 'max:30', Rule::unique('parties', 'ntn')->ignore($ignoreId)],
            'party.sales_tax_registration' => ['nullable', 'string', 'max:40', Rule::unique('parties', 'sales_tax_registration')->ignore($ignoreId)],
            'party.cnic' => ['nullable', 'string', 'max:25', Rule::unique('parties', 'cnic')->ignore($ignoreId)],
            'party.email' => ['nullable', 'email', 'max:254'], 'party.phone' => ['nullable', 'string', 'max:40'],
            'party.notes' => ['nullable', 'string', 'max:10000'], 'party.is_active' => ['required', 'boolean'],
            'profile.payment_term_id' => ['required', 'integer', 'exists:payment_terms,id'],
            'profile.purchase_notes' => ['nullable', 'string', 'max:1000'],
            'profile.contractor_notes' => ['nullable', 'string', 'max:1000'],
            'profile.sales_notes' => ['nullable', 'string', 'max:1000'],
            'profile.credit_limit' => ['numeric', 'min:0', 'max:9999999999999999.9999'],
            'profile.credit_hold' => ['boolean'],
            'profile.service_ids' => ['array'],
            'profile.service_ids.*' => ['integer', 'distinct', 'exists:processing_services,id'],
            'profile.lead_time_days' => ['sometimes', 'array'],
            'profile.lead_time_days.*' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'contacts' => ['nullable', 'array', 'max:30'],
            'contacts.*.id' => ['nullable', 'integer', 'exists:party_contacts,id'],
            'contacts.*.name' => ['nullable', 'string', 'max:150'], 'contacts.*.designation' => ['nullable', 'string', 'max:100'],
            'contacts.*.email' => ['nullable', 'email', 'max:254'], 'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'addresses' => ['nullable', 'array', 'max:30'],
            'addresses.*.id' => ['nullable', 'integer', 'exists:party_addresses,id'],
            'addresses.*.label' => ['nullable', 'string', 'max:60'],
            'addresses.*.address_type' => ['required_with:addresses.*.label', Rule::in(['business', 'billing', 'shipping'])],
            'addresses.*.address_line_1' => ['required_with:addresses.*.label', 'string', 'max:255'],
            'addresses.*.address_line_2' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['required_with:addresses.*.label', 'string', 'max:100'],
            'addresses.*.province' => ['nullable', 'string', 'max:100'], 'addresses.*.postal_code' => ['nullable', 'string', 'max:20'],
            'addresses.*.country_code' => ['required_with:addresses.*.label', 'regex:/^[A-Z]{2}$/'],
        ];
        if ($role === 'customer') {
            $rules['profile.credit_limit'][] = 'required';
            $rules['profile.credit_hold'][] = 'required';
        }
        if ($role === 'vendor') {
            $rules['profile.service_ids'][] = 'required';
            $rules['profile.service_ids'][] = 'min:1';
        }

        return $rules;
    }
}
