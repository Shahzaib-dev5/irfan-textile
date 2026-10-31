<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>{{ $title }} - {{ $document->number??'Draft' }}</title>
<style>
@page { size:A4 portrait; margin:28pt 28pt 42pt; }
body { font-family:"DejaVu Sans",sans-serif; color:#17342d; font-size:9pt; line-height:1.45; }
h1 {font-size:19pt; margin:0 0 5pt;} h2 {font-size:13pt;margin:0 0 4pt;}
p {margin:3pt 0;} .muted {color:#66776f;} .heading {border-bottom:2pt solid #176b58;padding-bottom:12pt;margin-bottom:14pt;}
.logo {max-width:100pt;max-height:48pt;float:right;} .meta {width:100%;border-collapse:collapse;margin:12pt 0;}
.meta th {text-align:left;width:28%;font-weight:normal;color:#66776f;padding:4pt;} .meta td {padding:4pt;word-wrap:break-word;}
.lines {width:100%;border-collapse:collapse;table-layout:fixed;} .lines th {background:#e9f3ef;text-align:left;padding:7pt 4pt;font-size:8pt;}
.lines th:first-child {width:26%;}
.lines td {border-bottom:1pt solid #dbe7e0;padding:7pt 4pt;vertical-align:top;font-size:8pt;word-wrap:break-word;white-space:pre-line;}
thead {display:table-header-group;} tr {page-break-inside:avoid;} .total {text-align:right;font-size:12pt;font-weight:bold;margin:14pt 0;}
.signatures {width:100%;margin-top:40pt;page-break-inside:avoid;} .signatures td {width:33%;text-align:center;padding-top:10pt;border-top:1pt solid #82978c;}
.notice {padding:8pt;background:#fff3d6;border:1pt solid #dfc37c;margin:10pt 0;} .watermark {position:fixed;top:250pt;left:45pt;color:#eeeeee;font-size:60pt;z-index:-1;}
.notes {white-space:pre-line;word-wrap:break-word;margin-top:15pt;} .toolbar {padding:10pt;background:#e9f3ef;margin-bottom:12pt;}
@media print {.toolbar {display:none;}}
</style></head><body>
@if($printable)<div class="toolbar"><button type="button" onclick="window.print()">Print {{ strtolower($title) }} / Save as PDF</button></div>@endif
@if($document->status!=='posted')<div class="watermark">{{ $document->status==='cancelled'?'CANCELLED':($document->status==='reversed'?'REVERSED':'DRAFT') }}</div>@endif
<div class="heading">@if($logo)<img class="logo" src="{{ $logo }}" alt="Company logo">@endif<h2>{{ $company?->legal_name }}</h2><p class="muted">{{ $company?->address_text }}</p><p class="muted">{{ $company?->phone }} {{ $company?->email }}</p></div>
<h1>{{ $title }}</h1><p><strong>{{ $document->number??'Draft #'.$document->id }}</strong> | Status: {{ App\Modules\Core\Support\DocumentPresentation::status($document->status) }}</p>
@if($document->status!=='posted')<p class="notice">{{ $document->status==='cancelled'?'Cancelled document. No longer available for confirmation.':($document->status==='reversed'?'Reversed document. Retained as posted history; its opposite entry records the correction.':'DRAFT - For review only. This document has not been confirmed.') }}</p>@endif
<table class="meta">@foreach($meta as $label=>$value)@if(filled($value))<tr><th>{{ $label }}</th><td>{{ $value }}</td></tr>@endif @endforeach</table>
<table class="lines"><thead><tr>@foreach($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead><tbody>@foreach($rows as $row)<tr>@foreach($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>@endforeach</tbody></table>
@if($total!==null)<p class="total">{{ $kind==='receipt'?'Accepted service charges':'Total' }}: PKR {{ $total }}</p>@endif
@if(filled($notes))<div class="notes"><strong>Notes</strong><p>{{ $notes }}</p></div>@endif
<table class="signatures"><tr><td>Prepared by</td><td>Checked / authorized by</td><td>Received / acknowledged by</td></tr></table>
<p class="muted" style="margin-top:16pt;font-size:8pt;">{{ $kind==='bill'?'This document records an obligation, not a payment receipt.':'Quantities and units refer to this document only.' }} Reprinted copies refer to the same transaction.</p>
</body></html>
