<?php

namespace App\Modules\Core\Services;

use App\Models\AuditEvent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AuditLogger
{
    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    public function record(string $entity, int|string $key, string $action, ?array $before, ?array $after, ?int $actorId = null, ?int $documentId = null): AuditEvent
    {
        if (! in_array($entity, ['ledger_accounts', 'bank_accounts', 'bank_statements', 'product_pack_specs', 'bills_of_materials', 'processing_services', 'processing_route_versions', 'warehouses', 'warehouse_locations', 'documents', 'stock_lots', 'stock_reservations', 'stock_balances', 'users', 'access_roles', 'companies', 'company_settings', 'financial_years', 'accounting_periods', 'parties', 'party_roles', 'party_contacts', 'party_addresses', 'supplier_profiles', 'vendor_profiles', 'customer_profiles', 'vendor_services', 'vendor_service_rates', 'units', 'item_categories', 'items', 'fabric_specifications', 'item_unit_conversions', 'designs', 'colors', 'product_variants'], true)) {
            throw new InvalidArgumentException('Audit entity is not allowlisted.');
        }
        $request = app()->bound('request') ? request() : null;
        $requestKey = $request?->attributes->get('request_key');
        if ($request && ! $requestKey) {
            $requestKey = (string) Str::uuid();
            $request->attributes->set('request_key', $requestKey);
        }

        return AuditEvent::query()->create([
            'actor_id' => $actorId ?? Auth::id(),
            'document_id' => $documentId,
            'entity_table' => $entity,
            'entity_key' => (string) $key,
            'action' => $action,
            'before_json' => $this->redact($before),
            'after_json' => $this->redact($after),
            'request_key' => $requestKey ?? (string) Str::uuid(),
            'ip_address' => $request?->ip(),
        ]);
    }

    private function redact(?array $snapshot): ?array
    {
        if ($snapshot === null) {
            return null;
        }

        $sensitive = ['password', 'password_hash', 'remember_token', 'token', 'secret', 'api_key', 'bank_secret'];
        foreach ($snapshot as $key => $value) {
            if (in_array(mb_strtolower((string) $key), $sensitive, true)) {
                unset($snapshot[$key]);
            } elseif (is_array($value)) {
                $snapshot[$key] = $this->redact($value);
            }
        }

        return $snapshot;
    }
}
