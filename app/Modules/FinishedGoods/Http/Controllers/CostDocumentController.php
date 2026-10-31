<?php

namespace App\Modules\FinishedGoods\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Modules\Core\Services\TransactionDocument;
use App\Modules\Core\Services\TransactionDocumentRenderer;
use Illuminate\Http\Request;

class CostDocumentController extends Controller
{
    public function __invoke(Request $r, Document $document, TransactionDocument $documents, TransactionDocumentRenderer $renderer)
    {
        return $renderer->render($documents->costing($document), $r->route('format') === 'print');
    }
}
