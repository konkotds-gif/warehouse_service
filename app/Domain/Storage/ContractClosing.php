<?php

namespace App\Domain\Storage;

use App\Domain\Support\DomainError;
use App\Models\ContractSmsCode;
use App\Models\StorageContract;
use App\Models\User;
use App\Models\WheelSet;
use Illuminate\Support\Facades\DB;

/**
 * Закрытие договора хранения: клиент забирает оставшиеся колёса и подтверждает закрытие кодом из SMS
 * (как при подписании). При закрытии считается плата за хранение после срока по условиям из «Тарифов»
 * (Pricing::overdue) — её нужно принять в кассе до закрытия; сумма уходит в 1С (set.issued.overdue, contract.closed).
 *
 * Без кода: если закрытие по SMS выключено/SMSC не подключён или у клиента нет телефона, а также руководитель
 * (право fix) может закрыть без кода с указанием причины — например, SMS не доходит.
 */
final class ContractClosing
{
    public function __construct(
        private readonly IssueService $issue,
        private readonly ContractConfirmation $confirmation,
    ) {}

    /**
     * Расчёт закрытия для карточки договора: что выдаём, что мешает, сколько доплатить.
     *
     * @return array<string, mixed>
     */
    public function quote(StorageContract $contract, ?User $user = null): array
    {
        $active = $contract->sets()->with(['location', 'issueStore'])->whereIn('status', SetStatus::activeValues())->get();
        $blockers = [];
        $toIssue = [];
        foreach ($active as $s) {
            /** @var WheelSet $s */
            if ($s->status !== SetStatus::Ready) {
                $hint = in_array($s->status, [SetStatus::Placed, SetStatus::Received, SetStatus::Accepted], true)
                    ? ' — сначала закажите его к выдаче' : ' — дождитесь, когда его привезут в магазин';
                $blockers[] = "Комплект {$s->qr_code}: «{$s->status->label()}»{$hint}";
            } elseif ($user && ! IssueService::userAtLocation($user, $s->location)) {
                $blockers[] = "Комплект {$s->qr_code} готов к выдаче в другом магазине: ".$s->location?->label();
            } else {
                $toIssue[] = ['id' => $s->id, 'qr_code' => $s->qr_code, 'qty' => $s->qty, 'tire_size' => $s->tire_size];
            }
        }
        $debt = $this->issue->debtCheck($contract);
        if ($debt['blocked']) {
            $blockers[] = 'Долг по договору '.number_format($debt['debt'], 2, ',', ' ').' ₽ (данные 1С) — клиент должен оплатить в кассе';
        }
        $overdue = $debt['overdue'];
        $closed = $contract->status === ContractStatus::Closed;

        return [
            'closed' => $closed,
            'can_close' => ! $closed && $blockers === [],
            'blockers' => $closed ? [] : $blockers,
            'to_issue' => $toIssue,
            'issued' => $contract->sets()->where('status', SetStatus::Issued->value)->count(),
            'overdue' => $overdue,
            'due' => $overdue['due'],
            'requires_code' => $this->confirmation->requiresCloseCode($contract),
            'sms_enabled' => $this->confirmation->closeEnabled(),
            'phone' => $contract->client?->phone,
            'can_override' => (bool) $user?->can('fix'),
        ];
    }

    /** Отправить клиенту код закрытия. В тексте SMS — сумма доплаты за хранение после срока, если она есть. */
    public function sendCode(StorageContract $contract, ?User $user = null): ContractSmsCode
    {
        $quote = $this->quote($contract, $user);
        if ($quote['closed']) {
            throw new DomainError('Договор уже закрыт');
        }
        if (! $quote['can_close']) {
            throw new DomainError($quote['blockers'][0]);
        }
        if (! $quote['requires_code']) {
            throw new DomainError($quote['sms_enabled'] ? 'У клиента не указан телефон — договор закрывается без кода' : 'Закрытие по SMS выключено — договор закрывается без кода');
        }
        $sum = $quote['due'] > 0 ? ', доплата за хранение '.number_format($quote['due'], 0, ',', ' ').' руб.' : '';

        return $this->confirmation->send($contract, $user, ContractConfirmation::CLOSE, ['{sum}' => $sum]);
    }

    /**
     * Закрыть договор: проверить код из SMS, выдать оставшиеся комплекты (уже принятые в магазине), начислить плату за просрочку.
     *
     * @param  array{code?: ?string, paid?: bool, reason?: ?string}  $input
     */
    public function close(StorageContract $contract, array $input, ?User $user = null): StorageContract
    {
        // Неверный код должен сохранить счётчик попыток — ошибку кода бросаем уже после транзакции
        $result = DB::transaction(function () use ($contract, $input, $user) {
            $contract = StorageContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $quote = $this->quote($contract, $user);
            if ($quote['closed']) {
                throw new DomainError('Договор уже закрыт');
            }
            if (! $quote['can_close']) {
                throw new DomainError($quote['blockers'][0]);
            }
            if ($quote['due'] > 0 && empty($input['paid'])) {
                throw DomainError::invalid('Примите в кассе оплату за хранение после срока — '
                    .number_format($quote['due'], 2, ',', ' ').' ₽ — и отметьте её', 'paid');
            }

            $reason = trim((string) ($input['reason'] ?? ''));
            if (! $quote['requires_code']) {
                $closing = ['confirmation' => 'none', 'note' => $quote['phone'] ? 'Закрытие по SMS выключено' : 'У клиента нет телефона'];
            } elseif ($reason !== '') {
                if (! $user?->can('fix')) {
                    throw new DomainError('Закрыть договор без кода из SMS может только руководитель', 403);
                }
                $closing = ['confirmation' => 'manual', 'note' => $reason];
            } else {
                $sent = $this->confirmation->consume($contract, (string) ($input['code'] ?? ''), ContractConfirmation::CLOSE);
                if (is_string($sent)) {
                    return $sent;
                }
                $closing = ['confirmation' => 'sms', 'phone' => $sent->phone];
            }

            // Скан бирки не нужен: комплекты «Готов к выдаче» уже приняты в магазине кладовщиком/продавцом.
            // Последний выдаваемый комплект закрывает договор с реквизитами закрытия (IssueService::issue)
            $ids = array_column($quote['to_issue'], 'id');
            foreach ($ids as $i => $id) {
                $set = WheelSet::findOrFail($id);
                $this->issue->issue($set, $contract->number, null, $user, $i === array_key_last($ids) ? $closing : null);
            }
            $contract->refresh();
            if ($contract->status !== ContractStatus::Closed) {
                // Все комплекты уже были выданы раньше — закрываем сами, с платой за просрочку, если она есть
                if ($quote['due'] > 0) {
                    $contract->update(['overdue_fee' => $quote['overdue']['amount'], 'overdue_days' => $quote['overdue']['days']]);
                }
                $contract = $this->issue->closeContract($contract, $user, $closing);
            }

            return $contract;
        });
        if (is_string($result)) {
            throw DomainError::invalid($result, 'code');
        }

        return $result;
    }

    /** Подпись способа закрытия для карточки. */
    public static function confirmationLabel(?string $value): ?string
    {
        return match ($value) {
            'sms' => 'клиент подтвердил кодом из SMS',
            'manual' => 'без кода из SMS (решение руководителя)',
            'none' => 'без кода из SMS',
            'auto' => 'автоматически',
            default => null,
        };
    }
}
