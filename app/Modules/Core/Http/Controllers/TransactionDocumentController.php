<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Modules\Core\Services\TransactionDocument;
use App\Modules\Core\Services\TransactionDocumentRenderer;
use Illuminate\Http\Request;

class TransactionDocumentController extends Controller
{
    public function __invoke(Request $request, Document $document, TransactionDocument $documents, TransactionDocumentRenderer $renderer)
    {
        return $renderer->render($documents->data($document, $request->route('document_module')), $request->route('document_format') === 'print');
    }
}
