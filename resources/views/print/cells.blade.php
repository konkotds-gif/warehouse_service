<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Этикетки ячеек {{ $location->code }}</title>
<style>
    @page { size: 58mm 40mm; margin: 0; }
    body { margin: 0; font-family: Arial, Helvetica, sans-serif; }
    .label { width: 58mm; height: 40mm; display: flex; align-items: center; gap: 2mm; padding: 2mm; box-sizing: border-box; page-break-after: always; }
    .label svg { width: 32mm; height: 32mm; flex: none; }
    .addr { font-size: 17pt; font-weight: 800; letter-spacing: .02em; }
    .sub { font-size: 8pt; color: #333; }
    .noprint { position: fixed; top: 8px; right: 8px; }
    @media print { .noprint { display: none; } }
    @media screen { body { background: #eee; } .label { background: #fff; margin: 8px auto; border: 1px dashed #999; } }
</style>
</head>
<body onload="window.print()">
<button class="noprint" onclick="window.print()">Печать</button>
@foreach ($cells as $c)
    <div class="label">{!! $c['svg'] !!}<div><div class="addr">{{ $c['address'] }}</div><div class="sub">Склад {{ substr($c['address'], 0, 2) }} · Ряд {{ substr($c['address'], 3, 2) }} · Ячейка {{ substr($c['address'], 6, 2) }}</div></div></div>
@endforeach
</body>
</html>
