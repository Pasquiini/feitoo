<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Orçamento</title>
    <style>
        @page { size: A4 portrait; margin: 38pt 36pt 52pt; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; line-height: 1.45; color: #263832; }
        table { border-collapse: collapse; width: 100%; table-layout: fixed; }
        td, th { vertical-align: top; }
        .running { position: fixed; top: -23pt; left: 0; right: 0; font-size: 7pt; color: #718078; letter-spacing: .6pt; }
        .brand { margin-bottom: 15pt; border-bottom: 1pt solid #d7dfda; padding-bottom: 12pt; }
        .brand td { padding: 0; }
        .provider { font-size: 15pt; font-weight: bold; line-height: 1.25; overflow-wrap: break-word; }
        .provider-note { margin-top: 5pt; font-size: 8pt; color: #65736c; }
        .logo { text-align: right; width: 100pt; }
        .logo img { max-width: 86pt; max-height: 50pt; }
        .heading { margin-bottom: 14pt; }
        .eyebrow { font-size: 8pt; color: #65736c; letter-spacing: 1pt; text-transform: uppercase; margin-bottom: 5pt; }
        h1 { margin: 0 0 6pt; font-size: 27pt; line-height: 1.15; font-weight: normal; color: #23362e; }
        .reference { font-size: 10pt; color: #52645a; overflow-wrap: break-word; }
        .dates { padding-top: 4pt; text-align: right; width: 165pt; font-size: 8.5pt; color: #52645a; }
        .dates strong { color: #263832; }
        .parties { margin-bottom: 16pt; }
        .parties td { width: 50%; padding: 9pt 12pt; border-top: 1pt solid #d7dfda; border-bottom: 1pt solid #d7dfda; background: #f8faf8; }
        .parties .party-left { border-right: 7pt solid white; }
        .section-label { font-size: 7.5pt; letter-spacing: .8pt; color: #65736c; text-transform: uppercase; margin-bottom: 5pt; }
        .party-name { font-size: 10pt; font-weight: bold; margin-bottom: 3pt; overflow-wrap: break-word; }
        .detail { font-size: 8.3pt; color: #52645a; overflow-wrap: break-word; }
        .items-title { font-size: 11pt; font-weight: bold; margin-bottom: 8pt; }
        .currency { font-size: 7.5pt; color: #65736c; font-weight: normal; }
        .items thead { display: table-header-group; }
        .items th { padding: 9pt 6pt; font-size: 8pt; font-weight: bold; color: #52645a; border-bottom: 1pt solid #bdc9c1; background: #f1f4f1; }
        .items td { padding: 7pt 6pt; border-bottom: .6pt solid #e3e9e4; }
        .items tr { page-break-inside: avoid; }
        .items .last-item-part { page-break-after: avoid; }
        .items .totals-row { page-break-before: avoid; }
        .items td.totals-container { padding: 0; border: 0; }
        .item-index { color: #7b887f; font-size: 8pt; }
        .description-line { font-size: 9.5pt; line-height: 14pt; white-space: pre; }
        .numeric { text-align: right; white-space: nowrap; font-size: 8.5pt; }
        .continued td { border-bottom-color: #e3e9e4; padding-top: 3pt; }
        .continuation { color: #7b887f; font-size: 6.5pt; }
        .totals { margin: 13pt 0 16pt auto; width: 245pt; page-break-inside: avoid; }
        .totals td { padding: 5pt 10pt; font-size: 9pt; border: 0; }
        .totals .amount { text-align: right; white-space: nowrap; }
        .totals .grand td { padding: 11pt 10pt; font-size: 13pt; border-top: 1pt solid #263832; font-weight: bold; }
        .text-section { margin: 0 0 16pt; }
        .text-chunk { page-break-inside: avoid; padding: 0 0 3pt; }
        .text-section h2 { font-size: 10pt; margin: 0 0 7pt; font-weight: bold; }
        .text-line { font-size: 9.5pt; line-height: 14pt; white-space: pre; }
        @if($modern)
        .brand { border-bottom: 3pt solid #125d54; }
        .provider { color: #125d54; font-size: 17pt; }
        h1 { font-size: 30pt; font-weight: bold; color: #125d54; }
        .eyebrow { color: #125d54; }
        .parties td { background: #f0f5f1; border-color: #d7e2d9; }
        .items th { background: #125d54; color: white; border-bottom: none; }
        .items-title { color: #125d54; }
        .totals .grand td { background: #125d54; color: white; border-top: none; }
        .section-label { color: #125d54; }
        @endif
    </style>
</head>
<body>
<div class="running">FEITOO &nbsp; / &nbsp; ORÇAMENTO PROFISSIONAL</div>
<table class="brand"><tr>
    <td><div class="provider">@foreach($issuerHeading as $line)<div>{{ $line }}</div>@endforeach</div><div class="provider-note">Serviços e soluções sob medida</div></td>
    @if($logoUri)<td class="logo"><img src="{{ $logoUri }}" alt="Logotipo"></td>@endif
</tr></table>
<table class="heading"><tr>
    <td><div class="eyebrow">Uma proposta para o seu projeto</div><h1>Orçamento</h1><div class="reference">@foreach($numberLines as $line)<div>{{ $line }}</div>@endforeach</div></td>
    <td class="dates">Emitido em <strong>{{ implode('/', array_reverse(explode('-', $quotation->issueDate))) }}</strong><br>Válido até <strong>{{ implode('/', array_reverse(explode('-', $quotation->validUntil))) }}</strong></td>
</tr></table>
<table class="parties"><tr>
    @foreach($parties as $party)
    <td @if($loop->first) class="party-left" @endif>
        <div class="section-label">{{ $party['label'] }}</div>
        <div class="party-name">@foreach($party['name'] as $line)<div>{{ $line }}</div>@endforeach</div>
        @foreach($party['details'] as $line)<div class="detail">{{ $line }}</div>@endforeach
    </td>
    @endforeach
</tr></table>
<div class="items-title">Produtos e serviços <span class="currency">&nbsp; · &nbsp; Valores em R$</span></div>
<table class="items">
    <thead><tr><th style="width:5%">#</th><th style="width:48%;text-align:left">Descrição</th><th style="width:12%;text-align:right">Qtd.</th><th style="width:17.5%;text-align:right">Unitário</th><th style="width:17.5%;text-align:right">Valor</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        <tr class="{{ $row['continuation'] ? 'continued' : '' }} {{ $loop->last ? 'last-item-part' : '' }}">
            <td class="item-index">{{ $row['continuation'] ? '↳' : str_pad((string) $row['index'], 2, '0', STR_PAD_LEFT) }}</td>
            <td>@foreach($row['lines'] as $line)<div class="description-line">{{ $line === '' ? ' ' : $line }}</div>@endforeach</td>
            <td class="numeric">{{ $row['continuation'] ? '' : \App\Domain\Quotations\QuotationCalculator::quantity($row['item']->quantity) }}</td>
            <td class="numeric">{{ $row['continuation'] ? '' : \App\Domain\Quotations\QuotationCalculator::money($row['item']->unitPriceCents) }}</td>
            <td class="numeric">{{ $row['continuation'] ? '' : \App\Domain\Quotations\QuotationCalculator::money($row['item']->totalCents) }}</td>
        </tr>
    @endforeach
    <tr class="totals-row"><td colspan="5" class="totals-container"><table class="totals">
    <tr><td>Subtotal</td><td class="amount">R$ {{ \App\Domain\Quotations\QuotationCalculator::money($quotation->totals->subtotalCents) }}</td></tr>
    <tr><td>Desconto @if($quotation->discountType === 'percent')({{ str_replace('.', ',', (string) $quotation->discountValue) }}%)@endif</td><td class="amount">R$ {{ \App\Domain\Quotations\QuotationCalculator::money($quotation->totals->discountCents) }}</td></tr>
    <tr class="grand"><td>Total</td><td class="amount">R$ {{ \App\Domain\Quotations\QuotationCalculator::money($quotation->totals->totalCents) }}</td></tr>
    </table></td></tr>
    </tbody>
</table>
@foreach($sections as $section)
    <div class="text-section">
    @foreach($section['chunks'] as $chunk)
        <div class="text-chunk">
            @if($loop->first)<h2>{{ $section['title'] }}</h2>@endif
            @foreach($chunk as $line)<div class="text-line">{{ $line === '' ? ' ' : $line }}</div>@endforeach
        </div>
    @endforeach
    </div>
@endforeach
</body>
</html>
