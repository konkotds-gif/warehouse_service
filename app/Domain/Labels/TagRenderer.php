<?php

namespace App\Domain\Labels;

use App\Models\StorageCell;
use App\Models\WheelSet;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Бирки на колёса и этикетки ячеек: QR (SVG) для печати из браузера и ZPL для термопринтеров.
 * Формат бирки — 58×40 мм. В QR — номер договора (одинаковый на всех бирках хранения).
 */
final class TagRenderer
{
    public function qrSvg(string $text, int $size = 160): string
    {
        $renderer = new ImageRenderer(new RendererStyle($size, 1), new SvgImageBackEnd);
        $svg = (new Writer($renderer))->writeString($text, 'UTF-8');

        return preg_replace('/^<\?xml.*?\?>\s*/', '', $svg) ?? $svg;
    }

    /** @return array<string, mixed> данные для бирки */
    public function tagData(WheelSet $set): array
    {
        $set->loadMissing(['contract.vehicle', 'contract.store', 'contract.client']);
        $contract = $set->contract;

        return [
            'qr_code' => $set->qr_code,
            'contract_number' => $contract->number,
            'plate' => $contract->vehicle?->plate ?? 'без номера',
            'vehicle' => trim(($contract->vehicle?->make ?? '').' '.($contract->vehicle?->model ?? '')),
            'tire_size' => $set->tire_size,
            'with_rims' => $set->with_rims ? 'С ДИСКАМИ' : 'БЕЗ ДИСКОВ',
            'condition' => $set->condition->label(),
            'qty' => $set->qty,
            'season' => $set->season?->label(),
            'store' => $contract->store?->code,
            'accepted_at' => $contract->accepted_at?->timezone(config('app.display_timezone'))->format('d.m.Y'),
            'svg' => $this->qrSvg($set->qr_code, 150),
        ];
    }

    /** ZPL для термопринтера 203 dpi, этикетка 58×40 мм (464×320 точек). */
    public function zpl(WheelSet $set, int $copies): string
    {
        $d = $this->tagData($set);
        $esc = fn (?string $s) => str_replace(['^', '~'], ' ', (string) $s);

        return implode("\n", [
            '^XA',
            '^CI28',
            '^PW464',
            '^LL320',
            '^FO12,20^BQN,2,5^FDQA,'.$esc($d['qr_code']).'^FS',
            '^FO200,18^A0N,46,46^FD'.$esc($d['qr_code']).'^FS',
            '^FO200,70^A0N,30,30^FD'.$esc($d['plate']).'^FS',
            '^FO200,108^A0N,26,26^FD'.$esc($d['tire_size']).'^FS',
            '^FO200,140^A0N,24,24^FD'.$esc($d['with_rims']).'^FS',
            '^FO200,170^A0N,24,24^FD'.$esc($d['condition']).' / '.$d['qty'].' ШТ^FS',
            '^FO12,250^A0N,22,22^FD'.$esc(trim($d['vehicle'].' · '.$d['store'].' · '.$d['accepted_at'], ' ·')).'^FS',
            '^PQ'.max(1, $copies).',0,1,Y',
            '^XZ',
        ])."\n";
    }

    /** ZPL этикетки ячейки: QR с адресом и крупный текст. */
    public function cellZpl(StorageCell $cell): string
    {
        return implode("\n", [
            '^XA', '^CI28', '^PW464', '^LL320',
            '^FO20,40^BQN,2,8^FDQA,'.$cell->address.'^FS',
            '^FO230,110^A0N,64,52^FD'.$cell->address.'^FS',
            '^XZ',
        ])."\n";
    }
}
