<?php

namespace App\Domain\Storage;

use App\Domain\Integration\OneC\Outbox;
use App\Domain\Notifications\Sms;
use App\Domain\Support\DomainError;
use App\Domain\Warehouse\TaskStatus;
use App\Domain\Warehouse\TaskType;
use App\Models\Location;
use App\Models\StorageContract;
use App\Models\Task;
use App\Models\User;
use App\Models\WheelSet;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Подготовка к выдаче (план, 6.4) и выдача (6.5).
 */
final class IssueService
{
    public function __construct(
        private readonly SetMover $mover,
        private readonly Outbox $outbox,
        private readonly ScanResolver $resolver,
        private readonly Sms $sms,
        private readonly Pricing $pricing,
        private readonly ContractConfirmation $confirmation,
    ) {}

    /** Заказ к выдаче в магазине $store к дате $date. */
    public function order(WheelSet $set, Location $store, CarbonInterface|string|null $date = null, ?User $user = null): WheelSet
    {
        if (! $store->isStore()) {
            throw DomainError::invalid('Выдача возможна только в магазине', 'store_id');
        }
        $date = $date ? Carbon::parse($date)->startOfDay() : now()->startOfDay();
        if ($date->lt(now()->startOfDay())) {
            throw DomainError::invalid('Дата выдачи уже прошла', 'date');
        }

        return DB::transaction(function () use ($set, $store, $date, $user) {
            $set = $this->mover->lock($set);
            if (! in_array($set->status, [SetStatus::Placed, SetStatus::Received, SetStatus::Accepted], true)) {
                throw new DomainError("Комплект {$set->qr_code} в статусе «{$set->status->label()}» нельзя заказать к выдаче");
            }
            $this->mover->transition($set, SetStatus::Ordered, 'order', [
                'issue_store_id' => $store->id,
                'issue_due_date' => $date->toDateString(),
            ], ['note' => "Выдача в {$store->code} на {$date->format('d.m.Y')}"]);

            if ($set->current_location_id === $store->id) {
                // Комплект уже в буфере магазина выдачи
                $this->mover->transition($set, SetStatus::Ready, 'ready');
                $this->sms->setReady($set);
            } else {
                $due = $date->copy()->subDay();
                Task::create([
                    'type' => TaskType::Pick,
                    'wheel_set_id' => $set->id,
                    'location_id' => $set->current_location_id,
                    'due_date' => $due->lt(now()->startOfDay()) ? now()->toDateString() : $due->toDateString(),
                    'status' => TaskStatus::Open,
                    'title' => sprintf('Отобрать %s (%s) к выдаче в %s на %s', $set->qr_code, $set->currentAddress(), $store->code, $date->format('d.m.Y')),
                    'created_by' => $user?->id,
                ]);
            }

            return $set->fresh();
        });
    }

    public function cancelOrder(WheelSet $set): WheelSet
    {
        return DB::transaction(function () use ($set) {
            $set = $this->mover->lock($set);
            if ($set->status !== SetStatus::Ordered) {
                throw new DomainError('Комплект не заказан к выдаче');
            }
            $location = $set->location;
            $to = match (true) {
                $set->current_cell_id !== null => SetStatus::Placed,
                $location?->isWarehouse() => SetStatus::Received,
                default => SetStatus::Accepted,
            };
            $this->mover->transition($set, $to, 'order_cancel', ['issue_store_id' => null, 'issue_due_date' => null]);
            Task::where('wheel_set_id', $set->id)->where('type', TaskType::Pick->value)->where('status', TaskStatus::Open->value)
                ->update(['status' => TaskStatus::Cancelled->value, 'completed_at' => now()]);

            return $set->fresh();
        });
    }

    /**
     * Проверка долга по данным 1С.
     *
     * @return array{blocked: bool, debt: float, warnings: list<string>, overdue: array<string, mixed>}
     */
    public function debtCheck(StorageContract $contract): array
    {
        $warnings = [];
        $debt = (float) $contract->debt;
        $fresh = (int) config('storage.debt_fresh_hours', 24);
        if ($contract->debt_updated_at === null) {
            $warnings[] = 'Нет данных о долге из 1С — проверьте оплату в 1С';
        } elseif ($contract->debt_updated_at->lt(now()->subHours($fresh))) {
            $warnings[] = 'Данные о долге из 1С устарели (обновлены '.$contract->debt_updated_at->timezone(config('app.display_timezone'))->format('d.m.Y H:i').')';
        }
        $overdue = $this->pricing->overdue($contract);
        if ($overdue['due'] > 0) {
            $warnings[] = sprintf('Хранение после срока: %d дн. × %s ₽%s = %s ₽ (бесплатно до %s) — оплатить в кассе',
                $overdue['days'], number_format($overdue['rate'], 0, ',', ' '),
                $overdue['per'] === 'wheel' ? " × {$overdue['wheels']} кол." : '',
                number_format($overdue['due'], 2, ',', ' '), Carbon::parse($overdue['free_until'])->format('d.m.Y'));
        } elseif ($overdue['ends_at'] && $overdue['free_until'] && now()->startOfDay()->gt(Carbon::parse($overdue['ends_at']))) {
            $warnings[] = 'Срок хранения истёк '.Carbon::parse($overdue['ends_at'])->format('d.m.Y')
                .' — бесплатно забрать до '.Carbon::parse($overdue['free_until'])->format('d.m.Y');
        }
        if ($debt > 0) {
            $warnings[] = 'Долг по договору: '.number_format($debt, 2, ',', ' ').' ₽';
        }

        return [
            'blocked' => $debt > 0 && (bool) config('storage.block_issue_on_debt', true),
            'debt' => $debt,
            'warnings' => $warnings,
            'overdue' => $overdue,
        ];
    }

    /**
     * Выдача клиенту. $scannedCode — QR с бирки выдаваемых колёс (защита от выдачи чужих колёс).
     * Выдача последнего комплекта закрывает договор. Если закрытие подтверждается кодом из SMS,
     * последний комплект выдаётся только через ContractClosing (он передаёт $closing — реквизиты закрытия).
     *
     * @param  array{confirmation: string, phone?: ?string, note?: ?string}|null  $closing
     */
    public function issue(WheelSet $set, string $scannedCode, ?int $qty = null, ?User $user = null, ?array $closing = null): WheelSet
    {
        return DB::transaction(function () use ($set, $scannedCode, $qty, $user, $closing) {
            $set = $this->mover->lock($set);
            if ($set->status !== SetStatus::Ready) {
                throw new DomainError("Выдать можно только комплект «Готов к выдаче» (сейчас: «{$set->status->label()}»)");
            }
            if ($user && ! $user->isAdmin() && $user->location_id && $user->location_id !== $set->current_location_id) {
                throw new DomainError('Комплект готов к выдаче в другом магазине: '.$set->location?->label());
            }
            $scanned = $this->resolver->sets($scannedCode)->pluck('id');
            if (! $scanned->contains($set->id)) {
                throw new DomainError('Бирка не от этого комплекта — проверьте колёса');
            }
            $contract = StorageContract::whereKey($set->contract_id)->lockForUpdate()->firstOrFail();
            $isLast = ! $contract->sets()->whereIn('status', SetStatus::activeValues())->whereKeyNot($set->id)->exists();
            if ($isLast && $closing === null && $this->confirmation->requiresCloseCode($contract)) {
                throw new DomainError('Это последний комплект по договору — выдайте его через «Закрыть договор»: клиент подтверждает закрытие кодом из SMS');
            }
            $check = $this->debtCheck($contract);
            if ($check['blocked']) {
                throw new DomainError('Выдача заблокирована: долг по договору '.number_format($check['debt'], 2, ',', ' ').' ₽ (данные 1С)');
            }

            // Плата за дни после срока и бесплатного периода — начисляется по договору один раз (накопительно)
            $overdue = $this->pricing->overdue($contract);
            if ($overdue['due'] > 0) {
                $contract->update(['overdue_fee' => $overdue['amount'], 'overdue_days' => $overdue['days']]);
            }

            $this->mover->confirmQty($set, $qty, 'Выдача');
            $this->mover->transition($set, SetStatus::Issued, 'issue', [
                'current_cell_id' => null,
                'current_location_id' => null,
                'transfer_id' => null,
            ], ['qty' => $qty ?? $set->qty]);
            $this->mover->closeTasks($set, TaskType::PrepareIssue, TaskType::Pick);

            if ($set->garageSet) {
                $set->garageSet->update(['state' => GarageSetState::OnVehicle, 'last_issued_at' => now()]);
            }

            $this->outbox->record('set.issued', [
                'contract_number' => $contract->number,
                'client_onec_guid' => $contract->client?->onec_guid,
                'store_code' => $set->issueStore?->code ?? $user?->location?->code,
                'issued_at' => now()->toIso8601String(),
                'overdue' => $overdue['due'] > 0 ? [
                    'service_code' => Pricing::settings()['overdue_service_code'],
                    'days' => $overdue['days'],
                    'rate' => $overdue['rate'],
                    'per' => $overdue['per'],
                    'amount' => $overdue['due'],
                ] : null,
            ] + $this->outbox->setPayload($set));

            $stillActive = $contract->sets()->whereIn('status', SetStatus::activeValues())->exists();
            if (! $stillActive) {
                $this->closeContract($contract, $user, $closing ?? ['confirmation' => 'none']);
            }

            return $set->fresh();
        });
    }

    /** Списание/утилизация (руководитель/администратор). */
    public function dispose(WheelSet $set, string $reason): WheelSet
    {
        return DB::transaction(function () use ($set, $reason) {
            $set = $this->mover->lock($set);
            $this->mover->transition($set, SetStatus::Disposed, 'dispose', [
                'current_cell_id' => null, 'current_location_id' => null, 'transfer_id' => null,
            ], ['note' => $reason]);
            $set->garageSet?->update(['state' => GarageSetState::Retired]);
            $contract = $set->contract;
            if (! $contract->sets()->whereIn('status', SetStatus::activeValues())->exists()) {
                $this->closeContract($contract, null, ['confirmation' => 'auto', 'note' => 'Все комплекты списаны']);
            }

            return $set->fresh();
        });
    }

    /**
     * Перевести договор в «Закрыт» с реквизитами закрытия и отправить contract.closed в 1С.
     *
     * @param  array{confirmation: string, phone?: ?string, note?: ?string}  $closing
     */
    public function closeContract(StorageContract $contract, ?User $user, array $closing): StorageContract
    {
        $contract->update([
            'status' => ContractStatus::Closed,
            'closed_at' => now(),
            'closed_by' => $user?->id,
            'close_confirmation' => $closing['confirmation'],
            'close_phone' => $closing['phone'] ?? null,
            'close_note' => isset($closing['note']) ? mb_substr((string) $closing['note'], 0, 255) : null,
        ]);
        $contract = $contract->fresh();
        if ($set = ContractConfirmation::mainSet($contract)) {
            $note = match ($closing['confirmation']) {
                'sms' => 'Клиент подтвердил закрытие кодом из SMS'.(! empty($closing['phone']) ? " ({$closing['phone']})" : ''),
                'manual' => 'Закрыт без кода из SMS: '.($closing['note'] ?? ''),
                'auto' => $closing['note'] ?? 'Закрыт системой',
                default => 'Закрыт при выдаче последнего комплекта',
            };
            if ((float) $contract->overdue_fee > 0) {
                $note .= ' · за хранение после срока '.number_format((float) $contract->overdue_fee, 2, ',', ' ')." ₽ ({$contract->overdue_days} дн.)";
            }
            Sms::logMovement($set, 'contract_closed', $note);
        }
        $this->outbox->record('contract.closed', $this->outbox->contractPayload($contract));

        return $contract;
    }
}
