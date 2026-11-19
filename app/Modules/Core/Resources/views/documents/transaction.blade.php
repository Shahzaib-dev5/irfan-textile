<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }} - {{ $document->number ?? 'Draft' }}</title>
    <style>
        @page { size: A4 portrait; margin: 26pt 30pt 48pt; }
        * { box-sizing: border-box; }
        body { font-family: "DejaVu Sans", sans-serif; color: #19352f; font-size: 9pt; line-height: 1.45; }
        p { margin: 0; }
        .toolbar { padding: 10pt 12pt; margin-bottom: 16pt; background: #eaf4f0; border: 1pt solid #d5e6df; }
        .toolbar button { padding: 7pt 12pt; color: #fff; background: #176b58; border: 0; }
        .top-rule { height: 5pt; margin-bottom: 16pt; background: #176b58; }
        .brand-header { width: 100%; border-collapse: collapse; margin-bottom: 20pt; }
        .brand-header td { vertical-align: middle; }
        .brand { width: 68%; }
        .brand-mark { display: inline-block; width: 48pt; height: 48pt; margin-right: 10pt; color: #fff; background: #176b58; text-align: center; line-height: 48pt; font-size: 16pt; font-weight: bold; vertical-align: middle; }
        .logo { display: inline-block; width: 70pt; height: 70pt; margin-right: 10pt; object-fit: contain; vertical-align: middle; }
        .company { display: inline-block; max-width: 285pt; vertical-align: middle; }
        .eyebrow { margin-bottom: 3pt; color: #668078; font-size: 7pt; font-weight: bold; letter-spacing: 1pt; text-transform: uppercase; }
        .company-name { margin: 0; color: #173f35; font-size: 15pt; font-weight: bold; }
        .company-details { margin-top: 3pt; color: #61766f; font-size: 8pt; }
        .document-chip { width: 32%; padding: 9pt 11pt; text-align: right; background: #f0f6f3; border: 1pt solid #dce9e3; }
        .document-chip .label { color: #6d8179; font-size: 7pt; letter-spacing: .7pt; text-transform: uppercase; }
        .document-chip .number { margin-top: 4pt; color: #173f35; font-size: 11pt; font-weight: bold; }
        .document-chip .status { margin-top: 5pt; color: #176b58; font-size: 8pt; font-weight: bold; }
        .draft .document-chip .status { color: #94640a; }
        .cancelled .document-chip .status, .reversed .document-chip .status { color: #a33b35; }
        .title-row { padding-bottom: 10pt; border-bottom: 1pt solid #d9e5df; }
        h1 { margin: 0; color: #173f35; font-size: 20pt; line-height: 1.2; }
        .module-label { margin-top: 4pt; color: #71847d; font-size: 8pt; text-transform: uppercase; letter-spacing: .7pt; }
        .notice { margin: 12pt 0; padding: 8pt 10pt; color: #745311; background: #fff7e5; border: 1pt solid #e9d8a8; }
        .meta { width: 100%; margin: 14pt 0 17pt; border-collapse: separate; border-spacing: 5pt; }
        .meta td { width: 50%; padding: 7pt 9pt; vertical-align: top; background: #f5f8f6; border: 1pt solid #e4ece7; }
        .meta .meta-label { display: block; margin-bottom: 3pt; color: #72847d; font-size: 7pt; text-transform: uppercase; letter-spacing: .5pt; }
        .meta .meta-value { color: #263f37; font-size: 8.5pt; word-wrap: break-word; }
        .section-title { margin: 16pt 0 7pt; color: #31564b; font-size: 8pt; text-transform: uppercase; letter-spacing: .8pt; }
        .lines { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .lines thead { display: table-header-group; }
        .lines th { padding: 8pt 5pt; color: #fff; background: #245d4f; font-size: 7.5pt; font-weight: bold; text-align: left; word-wrap: break-word; }
        .lines td { padding: 7pt 5pt; border-bottom: 1pt solid #dfe8e3; vertical-align: top; color: #30473f; font-size: 7.5pt; word-wrap: break-word; white-space: pre-line; }
        .lines tbody tr:nth-child(even) { background: #f7faf8; }
        .numeric { text-align: right !important; }
        .empty { padding: 18pt !important; color: #71847d !important; text-align: center; }
        .total-wrap { margin-top: 11pt; text-align: right; }
        .total { display: inline-block; min-width: 190pt; padding: 9pt 12pt; color: #173f35; background: #eaf4f0; border-left: 3pt solid #176b58; text-align: right; }
        .total-label { color: #5f766d; font-size: 8pt; text-transform: uppercase; letter-spacing: .5pt; }
        .total-value { margin-top: 2pt; font-size: 14pt; font-weight: bold; }
        .notes { margin-top: 17pt; padding: 9pt 11pt; background: #f6f8f7; border-left: 2pt solid #a8c4b8; page-break-inside: avoid; }
        .notes-title { margin-bottom: 4pt; color: #426458; font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: .5pt; }
        .notes-body { color: #566c63; font-size: 8pt; white-space: pre-line; word-wrap: break-word; }
        .signatures { width: 100%; margin-top: 34pt; border-collapse: separate; border-spacing: 12pt 0; page-break-inside: avoid; }
        .signatures td { width: 33.33%; padding-top: 7pt; color: #61766f; border-top: 1pt solid #94a79f; text-align: center; font-size: 7.5pt; }
        .footnote { margin-top: 18pt; padding-top: 7pt; color: #75867f; border-top: 1pt solid #dce6e1; font-size: 7pt; }
        .watermark { position: fixed; top: 245pt; left: 95pt; color: #eff2f0; font-size: 52pt; font-weight: bold; z-index: -1; }
        tr { page-break-inside: avoid; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body class="{{ $document->status }}">
    @if($printable)
        <div class="toolbar"><button type="button" onclick="window.print()">Print {{ strtolower($title) }} / Save as PDF</button></div>
    @endif

    @if($document->status !== 'posted')
        <div class="watermark">{{ $document->status === 'cancelled' ? 'CANCELLED' : ($document->status === 'reversed' ? 'REVERSED' : 'DRAFT') }}</div>
    @endif

    <div class="top-rule"></div>
    <table class="brand-header">
        <tr>
            <td class="brand">
                @if($logo)
                    <img class="logo" src="{{ $logo }}" alt="Company logo">
                @else
                    <span class="brand-mark">{{ strtoupper(substr($company?->legal_name ?? 'IT', 0, 2)) }}</span>
                @endif
                <div class="company">
                    <p class="eyebrow">Textile manufacturing &amp; wholesale</p>
                    <p class="company-name">{{ $company?->legal_name ?? 'Company name' }}</p>
                    @if($company?->address_text)<p class="company-details">{{ $company->address_text }}</p>@endif
                    <p class="company-details">{{ collect([$company?->phone, $company?->email])->filter()->implode(' · ') }}</p>
                </div>
            </td>
            <td class="document-chip">
                <p class="label">Document number</p>
                <p class="number">{{ $document->number ?? 'Draft #'.$document->id }}</p>
                <p class="status">{{ App\Modules\Core\Support\DocumentPresentation::status($document->status) }}</p>
            </td>
        </tr>
    </table>

    <div class="title-row">
        <h1>{{ $title }}</h1>
        <p class="module-label">{{ str_replace('-', ' ', $module) }} document</p>
    </div>

    @if($document->status !== 'posted')
        <div class="notice">{{ $document->status === 'cancelled' ? 'Cancelled document. It is retained for history and is no longer available for confirmation.' : ($document->status === 'reversed' ? 'Reversed document. Its linked opposite entry records the correction.' : 'Draft preview only. This document has not been confirmed or posted.') }}</div>
    @endif

    @php($metaEntries = collect($meta)->filter(fn ($value) => filled($value))->all())
    @if($metaEntries)
        <table class="meta"><tbody>
            @foreach(array_chunk($metaEntries, 2, true) as $metaPair)
                <tr>
                    @foreach($metaPair as $label => $value)
                        <td><span class="meta-label">{{ $label }}</span><span class="meta-value">{{ $value }}</span></td>
                    @endforeach
                    @if(count($metaPair) === 1)<td></td>@endif
                </tr>
            @endforeach
        </tbody></table>
    @endif

    <p class="section-title">Document details</p>
    <table class="lines">
        <thead><tr>
            @foreach($columns as $column)
                @php($numericHeading = \Illuminate\Support\Str::contains(strtolower($column), ['quantity', 'accepted', 'rejected', 'pkr', 'amount', 'value', 'cost']))
                <th class="{{ $numericHeading ? 'numeric' : '' }}">{{ $column }}</th>
            @endforeach
        </tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $index => $cell)
                        @php($heading = $columns[$index] ?? '')
                        @php($numericCell = \Illuminate\Support\Str::contains(strtolower($heading), ['quantity', 'accepted', 'rejected', 'pkr', 'amount', 'value', 'cost']))
                        <td class="{{ $numericCell ? 'numeric' : '' }}">{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td class="empty" colspan="{{ max(count($columns), 1) }}">No line details recorded.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($total !== null)
        <div class="total-wrap"><div class="total">
            <p class="total-label">{{ $kind === 'receipt' ? 'Accepted service charges' : 'Document total' }}</p>
            <p class="total-value">PKR {{ $total }}</p>
        </div></div>
    @endif

    @if(filled($notes))
        <div class="notes"><p class="notes-title">Notes / explanation</p><p class="notes-body">{{ $notes }}</p></div>
    @endif

    <table class="signatures"><tr><td>Prepared by</td><td>Checked / authorized by</td><td>Received / acknowledged by</td></tr></table>
    <p class="footnote">{{ $kind === 'bill' ? 'This document records an obligation; it is not proof of payment.' : 'This document records the transaction details shown above.' }} Reprinted copies refer to the same transaction.</p>
</body>
</html>
