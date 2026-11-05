<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>{{ $title }}</title>
<style>
    @page { size: A4; margin: 10mm; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 10pt; }
    .doc { width: 190mm; margin: 0 auto; }
    h1 { font-size: 15pt; margin: 0 0 2mm; }
    h2 { font-size: 11.5pt; margin: 6mm 0 1mm; }
    .head { display: flex; justify-content: space-between; gap: 6mm; margin-bottom: 3mm; }
    .meta div { margin: .6mm 0; }
    table { width: 100%; border-collapse: collapse; margin: 2mm 0; }
    td, th { border: 1px solid #888; padding: 1.4mm 1.8mm; text-align: left; vertical-align: top; }
    th { background: #f0f0f0; font-size: 9pt; }
    .num { text-align: right; white-space: nowrap; }
    .box { width: 9mm; }
    .total td { font-weight: 700; }
    .sign { margin-top: 10mm; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 8mm; }
    .sign div { border-top: 1px solid #000; padding-top: 1mm; font-size: 8.5pt; }
    .muted { color: #444; font-size: 8.5pt; }
    .noprint { position: fixed; top: 8px; right: 8px; font: 14px Arial; }
    @media print { .noprint { display: none; } section { page-break-inside: avoid; } }
    @media screen { body { background: #eee; } .doc { background: #fff; padding: 10mm; margin: 10mm auto; } }
</style>
</head>
<body onload="window.print()">
<button class="noprint" onclick="window.print()">Печать</button>
@php
    $fmt = fn ($d) => $d ? $d->timezone($tz)->format('d.m.Y H:i') : '—';
    $allLines = $transfers->flatMap->lines;
@endphp
<div class="doc">
    <div class="head">
        <div class="meta">
            <h1>{{ $title }}</h1>
            <div>Дата: {{ $fmt($trip?->shipped_at ?? $trip?->created_at ?? $transfers->first()?->created_at) }}</div>
            <div>Водитель: <b>{{ $trip?->driver ?: ($transfers->first()?->driver ?: '________________________') }}</b></div>
            <div>Автомобиль: <b>{{ $trip?->vehicle ?: '________________________' }}</b></div>
        </div>
        <div class="meta" style="text-align:right">
            <div>Перемещений: <b>{{ $transfers->count() }}</b></div>
            <div>Договоров: <b>{{ $allLines->pluck('wheelSet.contract_id')->unique()->count() }}</b></div>
            <div>Комплектов: <b>{{ $allLines->count() }}</b></div>
            <div>Колёс: <b>{{ $allLines->sum(fn ($l) => $l->qty_out ?? $l->wheelSet->qty) }}</b></div>
        </div>
    </div>

    @if ($transfers->count() > 1)
        <table>
            <thead><tr><th>№</th><th>Перемещение</th><th>Откуда</th><th>Куда</th><th class="num">Компл.</th><th class="num">Колёс</th></tr></thead>
            <tbody>
            @foreach ($transfers as $i => $t)
                <tr>
                    <td>{{ $i + 1 }}</td><td><b>{{ $t->number }}</b></td>
                    <td>{{ $t->from->label() }}</td><td>{{ $t->to->label() }}</td>
                    <td class="num">{{ $t->lines->count() }}</td>
                    <td class="num">{{ $t->lines->sum(fn ($l) => $l->qty_out ?? $l->wheelSet->qty) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif

    @foreach ($transfers as $t)
        <section>
            <h2>Перемещение {{ $t->number }}: {{ $t->from->label() }} → {{ $t->to->label() }}</h2>
            @if ($t->notes)<div class="muted">{{ $t->notes }}</div>@endif
            <table>
                <thead><tr><th>№</th><th>Бирка (QR)</th><th>Клиент / госномер</th><th>Комплект</th><th>Адрес</th><th class="num">Колёс</th><th class="box">Погр.</th><th class="box">Прин.</th></tr></thead>
                <tbody>
                @foreach ($t->lines as $i => $l)
                    @php($s = $l->wheelSet)
                    <tr>
                        <td>{{ $i + 1 }}</td>
                        <td><b>{{ $s->qr_code }}</b></td>
                        <td>{{ $s->contract?->client?->full_name }}<br><span class="muted">{{ $s->contract?->vehicle?->plate }}</span></td>
                        <td>{{ $s->title() }}</td>
                        <td>{{ $s->currentAddress() }}</td>
                        <td class="num">{{ $l->qty_out ?? $s->qty }}</td>
                        <td>{{ $l->scanned_out ? '✓' : ($l->wheels_out ? $l->wheels_out.'/'.$s->qty : '') }}</td>
                        <td>{{ $l->scanned_in ? '✓' : ($l->wheels_in ? $l->wheels_in.'/'.($l->qty_out ?? $s->qty) : '') }}</td>
                    </tr>
                @endforeach
                <tr class="total"><td colspan="5">Итого по {{ $t->number }}</td><td class="num">{{ $t->lines->sum(fn ($l) => $l->qty_out ?? $l->wheelSet->qty) }}</td><td colspan="2"></td></tr>
                </tbody>
            </table>
        </section>
    @endforeach

    <div class="sign">
        <div>Отпустил (склад / магазин), подпись, ФИО</div>
        <div>Принял к перевозке (водитель), подпись, ФИО</div>
        <div>Принял (пункт назначения), подпись, ФИО</div>
    </div>
</div>
</body>
</html>
