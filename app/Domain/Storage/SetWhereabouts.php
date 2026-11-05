<?php

namespace App\Domain\Storage;

use App\Domain\Warehouse\TaskStatus;
use App\Domain\Warehouse\TaskType;
use App\Domain\Warehouse\TransferStatus;
use App\Models\Location;
use App\Models\Movement;
use App\Models\Task;
use App\Models\Transfer;
use App\Models\TransferLine;
use App\Models\WheelSet;
use Illuminate\Support\Collection;

/**
 * Понятное «где лежит сейчас» для карточки договора:
 * склад/магазин, ряд и ячейка, буфер, перемещение в пути, выдача.
 */
class SetWhereabouts
{
    /**
     * @param  Collection<int, WheelSet>  $sets
     * @return array<string, array<string, mixed>> по id комплекта
     */
    public static function forSets(Collection $sets): array
    {
        if ($sets->isEmpty()) {
            return [];
        }
        $sets->loadMissing(['cell.row', 'cell.location', 'location', 'transfer.from', 'transfer.to', 'transfer.trip']);
        $ids = $sets->pluck('id');

        // Комплект включён в перемещение, которое ещё не отгружено (ждёт погрузки)
        $pending = TransferLine::with(['transfer.to', 'transfer.trip'])
            ->whereIn('wheel_set_id', $ids)
            ->whereIn('transfer_id', Transfer::query()->select('id')->where('status', TransferStatus::Created->value))
            ->get()->keyBy('wheel_set_id');

        // Когда выдан / списан
        $final = Movement::query()->whereIn('wheel_set_id', $ids)
            ->whereIn('to_status', [SetStatus::Issued->value, SetStatus::Disposed->value])
            ->orderByDesc('id')->get()->unique('wheel_set_id')->keyBy('wheel_set_id');

        // Склад и магазин в одном здании: заказ проведён сразу, кладовщик снимает колёса с полки
        $clientTasks = Task::query()->whereIn('wheel_set_id', $ids)->where('type', TaskType::IssueToClient->value)
            ->whereIn('status', [TaskStatus::Open->value, TaskStatus::Done->value])
            ->orderByDesc('id')->get()->unique('wheel_set_id')->keyBy('wheel_set_id');

        $out = [];
        foreach ($sets as $set) {
            $out[$set->id] = self::describe($set, $pending->get($set->id), $final->get($set->id), $clientTasks->get($set->id));
        }

        return $out;
    }

    /**
     * @return array{kind: string, place: string, detail: string|null, address: string, location: string|null,
     *               row: string|null, cell: string|null, pending: string|null, building: array<string, mixed>|null}
     */
    public static function describe(WheelSet $set, ?TransferLine $pendingLine = null, ?Movement $final = null, ?Task $clientTask = null): array
    {
        $cell = $set->cell;
        $base = [
            'address' => $set->displayAddress(),
            'location' => null,
            'row' => null,
            'cell' => null,
            'pending' => null,
            'building' => null,
        ];

        $result = match (true) {
            $set->status === SetStatus::Issued => [
                'kind' => 'issued',
                'place' => 'Выдан клиенту',
                'detail' => $final ? 'Выдан '.$final->at->format('d.m.Y H:i') : null,
            ],
            $set->status === SetStatus::Disposed => [
                'kind' => 'disposed',
                'place' => 'Списан',
                'detail' => $final ? 'Списан '.$final->at->format('d.m.Y H:i') : null,
            ],
            $set->status === SetStatus::InTransit => self::inTransit($set),
            $cell !== null => [
                'kind' => 'cell',
                'place' => self::locationName($cell->location ?? $set->location),
                'detail' => trim(sprintf('Ряд %s%s · ячейка %s', $cell->row?->row_no ?? '?',
                    $cell->row?->title ? ' ('.$cell->row->title.')' : '', $cell->cell_no)),
                'location' => self::locationName($cell->location ?? $set->location),
                'row' => $cell->row?->row_no,
                'cell' => $cell->cell_no,
            ],
            $set->location !== null => self::buffer($set),
            default => ['kind' => 'unknown', 'place' => 'Местоположение не определено', 'detail' => null],
        };

        if ($set->status === SetStatus::Lost) {
            $result['kind'] = 'lost';
            $result['detail'] = 'Утерян / расхождение по инвентаризации. Последнее известное место: '
                .$result['place'].($result['detail'] ? ', '.mb_strtolower($result['detail']) : '');
            $result['place'] = 'Не найден';
        }

        if ($pendingLine && $pendingLine->transfer) {
            $t = $pendingLine->transfer;
            $result['pending'] = sprintf('Включён в перемещение %s → %s, ждёт погрузки%s', $t->number,
                self::locationName($t->to), $t->trip ? ' (ведомость '.$t->trip->number.')' : '');
        }

        // Готов к выдаче в магазине того же здания: пока задание открыто, колёса ещё на полке склада
        if ($clientTask && $set->status === SetStatus::Ready && $set->issue_store_id !== null) {
            $p = $clientTask->payload ?? [];
            $onShelf = $clientTask->status === TaskStatus::Open;
            $result['building'] = [
                'on_shelf' => $onShelf,
                'shelf' => $p['shelf'] ?? null,
                'transfer_number' => $p['transfer_number'] ?? null,
                'note' => $onShelf
                    ? sprintf('Склад в том же здании — без водителя (%s). Кладовщику: снять с полки %s и выдать клиенту', $p['transfer_number'] ?? '', $p['shelf'] ?? '')
                    : sprintf('Склад в том же здании — кладовщик снял колёса с полки %s, ждут клиента', $p['shelf'] ?? ''),
            ];
            $result['detail'] = $result['building']['note'];
        }

        return array_merge($base, $result);
    }

    /** @return array{kind: string, place: string, detail: string|null} */
    private static function inTransit(WheelSet $set): array
    {
        $t = $set->transfer;
        if (! $t) {
            return ['kind' => 'transit', 'place' => 'В пути', 'detail' => null];
        }
        $parts = ['Перемещение '.$t->number];
        if ($t->trip) {
            $parts[] = 'ведомость '.$t->trip->number;
        }
        if ($t->driver || $t->trip?->driver) {
            $parts[] = 'водитель '.($t->trip?->driver ?: $t->driver);
        }
        if ($t->shipped_at) {
            $parts[] = 'отгружено '.$t->shipped_at->format('d.m.Y H:i');
        }

        return [
            'kind' => 'transit',
            'place' => sprintf('В пути: %s → %s', $t->from?->code ?? '?', self::locationName($t->to)),
            'detail' => implode(' · ', $parts),
        ];
    }

    /** @return array{kind: string, place: string, detail: string, location: string} */
    private static function buffer(WheelSet $set): array
    {
        $loc = $set->location;
        $name = self::locationName($loc);
        $detail = match (true) {
            $set->status === SetStatus::Ready => 'Буфер выдачи магазина, готов к выдаче клиенту',
            $loc->isStore() => 'Буфер магазина, ещё не отправлен на склад',
            default => 'Зона приёмки склада, ждёт размещения в ячейку',
        };

        return ['kind' => 'buffer', 'place' => $name, 'detail' => $detail, 'location' => $name];
    }

    private static function locationName(?Location $loc): string
    {
        if (! $loc) {
            return '?';
        }

        return $loc->name ? $loc->name.' ('.$loc->code.')' : ($loc->isWarehouse() ? 'Склад ' : 'Магазин ').$loc->code;
    }
}
