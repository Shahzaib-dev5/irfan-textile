<?php

namespace App\Modules\Sales\Services;

use App\Models\Company;
use App\Models\Document;
use App\Models\PaymentTerm;
use App\Models\Warehouse;
use App\Modules\Core\Services\TransactionDocument;
use Illuminate\Support\Facades\Gate;

class SalesDocument
{
    public function data(Document $document): array
    {
        Gate::authorize('sales.view', $document);
        $s = app(SalesService::class);
        $kind = $s->kind($document);
        $h = $s->header($document);
        $customer = $kind === 'credit' ? $h->invoice->customer : $h->customer;
        $rows = [];
        $total = '0';
        foreach ($h->lines as $line) {
            if ($kind === 'order') {
                $item = $line->item;
                $qty = $line->quantity;
                $unit = $line->unit->code;
                $reference = $line->description;
                $money = $s->net($line);
            } elseif ($kind === 'dispatch') {
                $item = $line->orderLine->item;
                $qty = $line->base_quantity;
                $unit = $item->baseUnit->code;
                $reference = $line->orderLine->order->document->number.' / '.$line->position->lot->lot_number;
                $money = $line->net_revenue_amount;
            } elseif ($kind === 'invoice') {
                $item = $line->item;
                $qty = $line->quantity;
                $unit = $item->baseUnit->code;
                $reference = $line->matches->map(fn ($m) => $m->dispatchLine->dispatch->document->number)->implode(', ');
                $money = $line->net_amount;
            } elseif ($kind === 'return') {
                $item = $line->dispatchLine->orderLine->item;
                $qty = $line->base_quantity;
                $unit = $item->baseUnit->code;
                $reference = $line->dispatchLine->dispatch->document->number.' / '.$line->condition_code;
                $money = null;
            } else {
                $item = $line->match->line->item;
                $qty = $line->credited_base_quantity;
                $unit = $item->baseUnit->code;
                $reference = $h->invoice->document->number.' / '.$h->credit_kind;
                $money = $line->net_amount;
            }
            $row = [$item->sku."\n".$item->name, $reference, $this->decimal($qty), $unit];
            if (in_array($kind, ['order', 'invoice'])) {
                $row[] = $this->decimal($line->unit_price, true);
                $row[] = $line->discount_amount;
            }
            $row[] = $money === null ? 'Physical return only' : $money;
            $rows[] = $row;
            $total = bcadd($total, $money ?? '0', 4);
        }
        $meta = ['Document date' => $document->document_date->format('d M Y'), 'Customer' => $customer->party->legal_name.' / '.$customer->party->code, 'Contact' => $customer->party->phone, 'Address' => $h->ship_to_snapshot ?? $h->billing_address_snapshot ?? $h->invoice?->billing_address_snapshot];
        if ($kind === 'invoice') {
            $meta['Due date'] = $h->due_on->format('d M Y');
        }
        if ($h->payment_term_id) {
            $meta['Payment terms'] = PaymentTerm::find($h->payment_term_id)?->name;
        }
        if ($kind === 'dispatch') {
            $meta['Warehouse'] = Warehouse::find($h->warehouse_id)?->name;
            $meta['Carrier / tracking'] = trim(($h->carrier ?? '').' / '.($h->tracking_reference ?? ''), ' /');
        }
        $columns = in_array($kind, ['order', 'invoice']) ? ['Item', 'Source / description', 'Quantity', 'Unit', 'PKR / unit', 'Discount PKR', 'Net PKR'] : ['Item', 'Source / details', 'Quantity', 'Unit', 'Net PKR'];

        return ['document' => $document, 'module' => 'sales', 'kind' => $kind, 'company' => Company::find(1), 'logo' => app(TransactionDocument::class)->logo(), 'title' => $document->type->name, 'meta' => $meta, 'columns' => $columns, 'rows' => $rows, 'total' => $kind === 'return' ? null : $total, 'notes' => ($h->notes ?? $h->reason ?? '').' | Payments are recorded separately in Accounting & finance. Tax calculation is not enabled. Physical returns require a separate commercial credit.'];
    }

    private function decimal(string $value, bool $price = false): string
    {
        $value = rtrim(rtrim(bcadd($value, '0', $price ? 8 : 6), '0'), '.');

        return $price && ! str_contains($value, '.') ? $value.'.00' : $value;
    }
}
