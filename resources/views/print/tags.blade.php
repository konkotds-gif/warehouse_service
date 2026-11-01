<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Печать бирок</title>
<style>
    /* Бирка 58×40 мм для термопринтера; печать из браузера: масштаб 100%, без полей */
    @page { size: 58mm 40mm; margin: 0; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; }
    .tag { width: 58mm; height: 40mm; padding: 2mm; display: flex; gap: 2mm; page-break-after: always; overflow: hidden; }
    .tag:last-child { page-break-after: auto; }
    .qr { width: 22mm; flex: none; display: flex; flex-direction: column; align-items: center; }
    .qr svg { width: 22mm; height: 22mm; }
    .qr .num { font-size: 9pt; font-weight: 700; margin-top: 1mm; }
    .info { flex: 1; min-width: 0; font-size: 7.5pt; line-height: 1.25; }
    .info .big { font-size: 12pt; font-weight: 800; letter-spacing: .02em; }
    .info .plate { font-size: 10pt; font-weight: 700; }
    .info .row { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .muted { color: #333; }
    .receipt { width: 190mm; margin: 10mm auto; font-size: 11pt; page-break-before: always; }
    .receipt:first-child { page-break-before: auto; }
    .receipt h1 { font-size: 16pt; margin: 0 0 4mm; }
    .receipt table { width: 100%; border-collapse: collapse; margin: 4mm 0; }
    .receipt td, .receipt th { border: 1px solid #999; padding: 2mm; text-align: left; font-size: 10pt; }
    .receipt .head { display: flex; justify-content: space-between; gap: 8mm; }
    .receipt .sign { margin-top: 12mm; display: flex; justify-content: space-between; }
    .noprint { position: fixed; top: 8px; right: 8px; font: 14px Arial; }
    @media print { .noprint { display: none; } }
    @media screen { body { background: #eee; } .tag { background: #fff; margin: 8px auto; border: 1px dashed #999; } .receipt { background: #fff; padding: 10mm; } }
</style>
</head>
<body onload="window.print()">
<button class="noprint" onclick="window.print()">Печать</button>
@foreach ($tags as $t)
    <div class="tag">
        <div class="qr">{!! $t['svg'] !!}<div class="num">{{ $t['qr_code'] }}</div></div>
        <div class="info">
            <div class="big">№ {{ $t['qr_code'] }}</div>
            <div class="plate">{{ $t['plate'] ?: '—' }}</div>
            <div class="row">{{ $t['tire_size'] }}</div>
            <div class="row"><b>{{ $t['with_rims'] }}</b></div>
            <div class="row"><b>{{ $t['condition'] }}</b> · {{ $t['qty'] }} шт</div>
            <div class="row muted">{{ $t['vehicle'] }}</div>
            <div class="row muted">{{ $t['store'] }} · {{ $t['accepted_at'] }}</div>
        </div>
    </div>
@endforeach
@if ($receipt)
    @php($c = $receipt['contract'])
    <div class="receipt">
        <div class="head">
            <div>
                <h1>Договор (квитанция) хранения № {{ $c->number }}</h1>
                <div>Дата приёма: {{ $c->accepted_at->timezone($receipt['tz'])->format('d.m.Y H:i') }}</div>
                <div>Магазин приёма: {{ $c->store->name }} ({{ $c->store->code }})</div>
                <div>Клиент: {{ $c->client->full_name }}, {{ $c->client->phone }}</div>
                <div>Автомобиль: {{ $c->vehicle?->title() ?: '—' }}</div>
                <div>Срок хранения до: {{ $c->storage_until?->format('d.m.Y') ?: '—' }}@if($c->tariff) · Тариф: {{ $c->tariff->name }}, {{ number_format((float) $c->amount, 2, ',', ' ') }} ₽ @endif</div>
            </div>
            <div>{!! $receipt['svg'] !!}</div>
        </div>
        <table>
            <tr><th>Бирка (QR)</th><th>Кол-во колёс</th><th>Размер шин</th><th>Диски</th><th>Состояние</th><th>Сезон</th></tr>
            @foreach ($c->sets as $s)
                <tr><td>{{ $s->qr_code }}</td><td>{{ $s->qty }}</td><td>{{ $s->tire_size }}</td><td>{{ $s->with_rims ? 'с дисками' : 'без дисков' }}</td>
                    <td>{{ $s->condition->label() }}</td><td>{{ $s->season?->label() ?: '—' }}</td></tr>
            @endforeach
        </table>
        <p style="font-size:9pt">Комплект выдаётся по предъявлении квитанции или документа, удостоверяющего личность. Клиент даёт согласие на обработку персональных данных (ФИО, телефон, госномер) в целях исполнения договора хранения (152-ФЗ).</p>
        <div class="sign"><div>Принял: ____________________</div><div>Клиент: ____________________</div></div>
    </div>
@endif
</body>
</html>
