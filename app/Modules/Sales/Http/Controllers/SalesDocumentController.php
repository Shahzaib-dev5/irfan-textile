<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Modules\Core\Services\TransactionDocumentRenderer;
use App\Modules\Sales\Services\SalesDocument;
use Illuminate\Http\Request;

class SalesDocumentController extends Controller
{
    public function __invoke(Request $r, Document $document, SalesDocument $source, TransactionDocumentRenderer $renderer)
    {
        return $renderer->render($source->data($document), $r->route('format') === 'print');
    }
}
