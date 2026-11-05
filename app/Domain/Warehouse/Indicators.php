<?php

namespace App\Domain\Warehouse;

use App\Domain\Storage\ContractStatus;
use App\Domain\Storage\SetStatus;
use App\Domain\Transfer\TransferService;
use App\Models\Location;
use App\Models\OutboxEvent;
use App\Models\StorageContract;
use App\Models\Task;
use App\Models\Transfer;
use App\Models\User;
use App\Models\WheelSet;

/**
 * Индикаторы актуальной работы для пользователя: что ждёт его внимания на его складе/в магазине.
 *
 * Набор зависит от прав роли (Role::abilities), счётчики — от локации пользователя
 * (без локации — по всей сети). Показываются в шапке («колокольчик»), бейджами в меню
 * и блоком «Требует внимания» на главной.
 */
class Indicators
{
    public function __construct(private readonly TransferService $transfers) {}

    /**
     * @return list<array{key: string, label: string, hint: string, count: int, tone: string, href: string, nav: string, icon: string}>
     */
    public function for(User $user, ?Location $location = null): array
    {
        $location ??= $user->location;
        $loc = $location?->id;
        $isStore = $location === null || $location->isStore();
        $isWarehouse = $location === null || $location->isWarehouse();
        $items = [];

        $add = function (string $key, string $label, string $hint, int $count, string $tone, string $href, string $nav, string $icon) use (&$items) {
            $items[] = compact('key', 'label', 'hint', 'count', 'tone', 'href', 'nav', 'icon');
        };

        // Машина в пути к нам: перемещения отгружены и едут в эту точку
        if ($user->hasAbility('receive-transfer')) {
            $add('inbound', $loc ? 'Едут к нам' : 'В пути', 'Перемещения в пути — встретить и принять по скану',
                Transfer::where('status', TransferStatus::Shipped->value)->when($loc, fn ($q) => $q->where('to_location_id', $loc))->count(),
                'blue', '/transfers?status=shipped'.($loc ? '&dir=in' : ''), '/transfers', 'truck');
        }

        // Перемещения из этой точки, которые ждут водителя / погрузки
        if ($user->hasAbility('transfers') || $user->hasAbility('ship-transfer')) {
            $add('outbound', 'Ждут погрузки', 'Перемещения с нашей точки в «Перевозках» — погрузить по скану',
                Transfer::where('status', TransferStatus::Created->value)->when($loc, fn ($q) => $q->where('from_location_id', $loc))->count(),
                'amber', '/tasks/transport', '/tasks', 'truck');

            $add('discrepancy', 'Расхождения', 'Перемещения, принятые с недостачей — разобрать',
                Transfer::where('status', TransferStatus::Discrepancy->value)
                    ->when($loc, fn ($q) => $q->where(fn ($q) => $q->where('from_location_id', $loc)->orWhere('to_location_id', $loc)))->count(),
                'red', '/transfers?status=discrepancy', '/transfers', 'alert');
        }

        // Задания сотрудникам склада (разместить, отобрать, расхождения инвентаризации)
        if ($user->hasAbility('tasks')) {
            $open = Task::where('status', TaskStatus::Open->value)->when($loc, fn ($q) => $q->where('location_id', $loc));
            $overdue = (clone $open)->whereDate('due_date', '<', now()->toDateString())->count();
            $add('tasks', 'Задания', $overdue ? "Открытые задания, из них просрочено: {$overdue}" : 'Открытые задания по точке',
                $open->count(), $overdue ? 'red' : 'blue', '/tasks', '/tasks', 'tasks');
        }

        // Привезли на склад, но ещё не разложили по ячейкам
        if ($user->hasAbility('place') && $isWarehouse) {
            $add('to_place', 'Ждут размещения', 'Комплекты на складе без ячейки — скан бирки → скан ячейки',
                WheelSet::where('status', SetStatus::Received->value)->when($loc, fn ($q) => $q->where('current_location_id', $loc))->count(),
                'amber', '/scan/place', '/scan/place', 'scan');
        }

        // Заказаны к выдаче, но ещё не включены в перемещение в магазин
        if ($user->hasAbility('pick') && $isWarehouse) {
            $add('to_issue_ship', 'Отправить к выдаче', 'Заказанные к выдаче комплекты, ещё не включённые в перемещение',
                $this->transfers->availableCount($location, SetStatus::Ordered),
                'amber', '/transfers/create'.($loc ? "?from={$loc}" : ''), '/transfers', 'box');
        }

        // Принято в буфер магазина и не отправлено на склад
        if ($user->hasAbility('transfers') && $user->hasAbility('accept') && $isStore) {
            $add('buffer', 'Не отправлены на склад', 'Принятые комплекты в буфере магазина, ещё не включённые в перемещение',
                $this->transfers->availableCount($location, SetStatus::Accepted),
                'amber', '/transfers/create'.($loc ? "?from={$loc}" : ''), '/transfers', 'box');
        }

        // Готовы к выдаче клиенту в магазине
        if ($user->hasAbility('issue') && $isStore) {
            $add('ready', 'Готовы к выдаче', 'Комплекты в буфере магазина ждут клиента',
                WheelSet::where('status', SetStatus::Ready->value)->when($loc, fn ($q) => $q->where('current_location_id', $loc))->count(),
                'green', '/scan/issue', '/scan/issue', 'issue');
        }

        // Просроченные договоры (клиент не забрал / не продлил)
        if ($user->hasAbility('clients')) {
            $add('overdue', 'Просрочены договоры', 'Договоры с истёкшим сроком хранения — связаться с клиентом',
                StorageContract::where('status', ContractStatus::Overdue->value)->when($isStore && $loc, fn ($q) => $q->where('store_id', $loc))->count(),
                'red', '/contracts?status=overdue'.($isStore && $loc ? "&store={$loc}" : ''), '/contracts', 'file');
        }

        // Администратору — зависшие события обмена с 1С
        if ($user->isAdmin()) {
            $add('onec', 'Обмен с 1С', 'События без подтверждения 1С дольше порога',
                OutboxEvent::whereNull('delivered_at')
                    ->where('created_at', '<', now()->subMinutes((int) config('storage.onec.ack_alert_minutes', 30)))->count(),
                'red', '/admin/integration', '/admin/integration', 'sync');
        }

        return $items;
    }
}
