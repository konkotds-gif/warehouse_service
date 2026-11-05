<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Пролонгация {{ $e->number }} к договору {{ $contract->number }}</title>
<style>
    /* Поля страницы = 0: браузеру некуда печатать колонтитулы (дата, URL, номера страниц).
       Отступы от края листа даёт таблица-рамка: thead/tfoot повторяются на каждой странице. */
    @page { size: A4; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 10.5pt; }
    .doc { width: 190mm; margin: 0 auto; }
    h1 { font-size: 15pt; margin: 0 0 1mm; }
    .sub { margin: 0 0 4mm; }
    .meta div { margin: .8mm 0; }
    table { width: 100%; border-collapse: collapse; margin: 4mm 0 2mm; }
    td, th { border: 1px solid #888; padding: 1.4mm 1.8mm; text-align: left; vertical-align: top; }
    th { background: #f0f0f0; font-size: 9pt; }
    .num { text-align: right; white-space: nowrap; }
    .total td { font-weight: 700; }
    .terms { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 3mm; margin: 4mm 0; }
    .terms div { border: 1px solid #888; padding: 2mm; }
    .terms b { display: block; font-size: 13pt; margin-top: 1mm; }
    .sign { margin-top: 12mm; display: grid; grid-template-columns: 1fr 1fr; gap: 12mm; }
    .sign div { border-top: 1px solid #000; padding-top: 1mm; font-size: 8.5pt; }
    .muted { color: #444; font-size: 8.5pt; }
    .noprint { position: fixed; top: 8px; right: 8px; font: 14px Arial; }
    @media print { .noprint { display: none; } }
    @media screen { body { background: #eee; } .doc { background: #fff; padding: 10mm; margin: 10mm auto; } }
    .frame { width: 100%; border-collapse: collapse; margin: 0; }
    .frame > thead > tr > td, .frame > tfoot > tr > td { height: 10mm; padding: 0; border: 0; }
    .frame > tbody > tr > td { padding: 0 10mm; border: 0; }
    @media screen { .frame > thead, .frame > tfoot { display: none; } .frame > tbody > tr > td { padding: 0; } }
</style>
</head>
<body onload="window.print()">
<button class="noprint" onclick="window.print()">Печать</button>
@php
    $money = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ');
    $lines = $e->lines ?? [];
@endphp
<table class="frame"><thead><tr><td></td></tr></thead><tfoot><tr><td></td></tr></tfoot><tbody><tr><td>
<div class="doc">
    <h1>Пролонгация договора хранения № {{ $e->number }}</h1>
    <p class="sub">от {{ $e->extended_at->timezone($tz)->format('d.m.Y') }} к договору (квитанции) хранения № <b>{{ $contract->number }}</b>
        от {{ $contract->accepted_at?->timezone($tz)->format('d.m.Y') ?: '—' }} · продление № {{ $e->seq }}</p>

    <div class="meta">
        <div>Клиент: <b>{{ $contract->client?->full_name ?: '—' }}</b>@if($contract->client?->phone), {{ $contract->client->phone }}@endif</div>
        <div>Автомобиль: {{ $contract->vehicle?->title() ?: '—' }}</div>
        <div>Магазин приёма: {{ $contract->store?->name }} ({{ $contract->store?->code }})</div>
    </div>

    <div class="terms">
        <div>Срок хранения был до<b>{{ $e->previous_until?->format('d.m.Y') ?: '—' }}</b></div>
        <div>Продление<b>{{ $e->months }} мес. с {{ $e->period_from->format('d.m.Y') }}</b></div>
        <div>Новая дата окончания хранения<b>{{ $e->storage_until->format('d.m.Y') }}</b></div>
    </div>

    <table>
        <thead><tr><th>№</th><th>Бирка (QR)</th><th>Размер шин</th><th>Услуга (код 1С)</th><th class="num">Колёс</th><th class="num">Мес.</th><th class="num">Цена за колесо/мес, ₽</th><th class="num">Сумма, ₽</th></tr></thead>
        <tbody>
        @foreach ($lines as $i => $l)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td><b>{{ $l['qr_code'] ?? '—' }}</b></td>
                <td>{{ ($l['tire_size'] ?? null) ?: (isset($l['diameter']) ? 'R'.$l['diameter'] : '—') }}</td>
                <td>{{ ($l['service_name'] ?? null) ?: 'Хранение'.(isset($l['diameter']) ? ' R'.$l['diameter'] : '') }}@if(! empty($l['service_code'])) <span class="muted">({{ $l['service_code'] }})</span>@endif</td>
                <td class="num">{{ $l['qty'] ?? '—' }}</td>
                <td class="num">{{ $l['months'] ?? $e->months }}</td>
                <td class="num">{{ $money($l['price'] ?? null) }}</td>
                <td class="num">{{ $money($l['amount'] ?? null) }}</td>
            </tr>
        @endforeach
        <tr class="total"><td colspan="7">Итого к оплате за продление</td><td class="num">{{ $money($e->amount) }}</td></tr>
        </tbody>
    </table>
    <p class="muted">Остальные условия договора хранения № {{ $contract->number }} не меняются. Оплата — в кассе магазина по документу 1С.
        Если колёса не забраны после новой даты окончания и бесплатных дней, начисляется плата за хранение после срока.</p>
    @if ($e->author)<p class="muted">Оформил: {{ $e->author->name }}</p>@endif

    <div class="sign">
        <div>Исполнитель (хранитель), подпись, ФИО</div>
        <div>Клиент: с условиями продления согласен, подпись, ФИО</div>
    </div>
</div>
</td></tr></tbody></table>
</body>
</html>
