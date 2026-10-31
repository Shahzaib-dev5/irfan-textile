<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Document;
use App\Models\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\PurchasingFixtures;
use Tests\TestCase;

class TransactionPdfLayoutTest extends TestCase
{
    use PurchasingFixtures, RefreshDatabase;

    public function test_long_draft_document_has_multiple_pdf_pages_and_escapes_notes_without_posting(): void
    {
        $this->purchasingFixtures();
        $data = ['kind' => 'order', 'document_date' => $this->date, 'idempotency_key' => (string) Str::uuid(), 'supplier_party_role_id' => $this->supplier->id, 'warehouse_id' => $this->source->warehouse_id, 'notes' => '<img src="https://example.invalid/private"> Unconfirmed review only.', 'lines' => array_fill(0, 60, ['item_id' => $this->item->id, 'unit_id' => $this->item->base_unit_id, 'quantity' => '100', 'unit_price' => '10', 'description' => 'Grey cotton fabric for manufacturing; supplier quality, width and composition must match the agreed specification.'])];
        $doc = $this->purchasing->save($this->admin->id, $data);
        CompanySetting::create(['setting_key' => 'company.logo', 'value_json' => ['path' => '../../.env'], 'updated_by' => $this->admin->id]);
        $this->get(route('purchasing.print', $doc))->assertOk()->assertSee('DRAFT - For review only.')->assertSee('&lt;img src=', false)->assertDontSee('<img src="https://example.invalid', false);
        $pdf = $this->get(route('purchasing.pdf', $doc))->assertOk();
        $this->assertGreaterThan(1, preg_match_all('#/Type\s*/Page\b#', $pdf->getContent()));
        $this->assertSame('draft', $doc->fresh()->status);
        $this->assertSame(1, Document::count());
        $this->assertSame(0, StockMovement::count());
        if (getenv('TRANSACTION_PDF_FIXTURES')) {
            file_put_contents(storage_path('framework/testing/transaction-pdf/multipage-draft.pdf'), $pdf->getContent());
        }
    }
}
