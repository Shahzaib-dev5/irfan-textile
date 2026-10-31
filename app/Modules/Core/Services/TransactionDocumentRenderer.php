<?php

namespace App\Modules\Core\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Str;

class TransactionDocumentRenderer
{
    public function render(array $data, bool $printable)
    {
        $document = $data['document'];
        $data['printable'] = $printable;
        if ($data['printable']) {
            return response()->view('module.core::documents.transaction', $data)->header('Cache-Control', 'private, no-store');
        }
        $directory = storage_path('framework/cache/document-pdf');
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $options = new Options([
            'isRemoteEnabled' => false, 'isPhpEnabled' => false, 'isJavascriptEnabled' => false,
            'chroot' => $directory, 'tempDir' => $directory, 'fontCache' => $directory,
            'defaultFont' => 'DejaVu Sans', 'isFontSubsettingEnabled' => true,
        ]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('module.core::documents.transaction', $data)->render(), 'UTF-8');
        $pdf->setPaper('A4', 'portrait');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $canvas->page_text(28, 815, $document->number ?? 'Draft #'.$document->id, $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.4, 0.4, 0.4]);
        $canvas->page_text(430, 815, 'Page {PAGE_NUM} of {PAGE_COUNT}', $pdf->getFontMetrics()->getFont('DejaVu Sans'), 8, [0.4, 0.4, 0.4]);
        $filename = Str::slug($data['title']).'-'.($document->number ? Str::slug($document->number) : 'draft-'.$document->id).'.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
