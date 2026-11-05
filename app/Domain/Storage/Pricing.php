<?php

namespace App\Domain\Storage;

use App\Domain\Support\DomainError;
use App\Models\Setting;
use App\Models\StorageContract;
use App\Models\Tariff;
use App\Models\WheelSet;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Стоимость хранения по прайсу: цена за 1 колесо в месяц по диаметру × колёса × месяцы.
 * После окончания срока — бесплатные дни, затем плата за каждый день (за договор или за колесо).
 * Параметры — в «Настройках» (ключ storage.pricing), цены — в «Тарифах».
 */
final class Pricing
{
    public const KEY = 'storage.pricing';

    public const DEFAULTS = [
        'max_months' => 7,          // срок хранения в мастере приёма: 1…N месяцев
        'free_days' => 7,           // бесплатных дней после окончания срока
        'overdue_rate' => 30,       // ₽ за день после бесплатных дней
        'overdue_per' => 'contract', // contract — за договор в день, wheel — за каждое колесо в день
        'overdue_service_code' => null, // код номенклатуры 1С для платы за просрочку (если заведена)
    ];

    /** @var Collection<int, Tariff>|null */
    private ?Collection $tariffs = null;

    /** @return array{max_months: int, free_days: int, overdue_rate: float, overdue_per: string, overdue_service_code: ?string} */
    public static function settings(): array
    {
        $s = array_replace(self::DEFAULTS, (array) Setting::get(self::KEY, []));

        return [
            'max_months' => max(1, (int) $s['max_months']),
            'free_days' => max(0, (int) $s['free_days']),
            'overdue_rate' => max(0, (float) $s['overdue_rate']),
            'overdue_per' => $s['overdue_per'] === 'wheel' ? 'wheel' : 'contract',
            'overdue_service_code' => filled($s['overdue_service_code']) ? (string) $s['overdue_service_code'] : null,
        ];
    }

    public static function saveSettings(array $data): void
    {
        Setting::put(self::KEY, array_intersect_key(array_replace(self::settings(), $data), self::DEFAULTS));
    }

    /** Посадочный диаметр из размера: '205/55 R16 94H' → 16, '195/75 R16C' → 16, '235/45 ZR18' → 18, '31x10.5 R15' → 15. */
    public static function diameter(?string $tireSize): ?int
    {
        if ($tireSize && preg_match('/Z?R\s*(\d{2})(?:[.,]5)?C?\b/iu', $tireSize, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    public function tariffFor(?int $diameter): ?Tariff
    {
        if ($diameter === null) {
            return null;
        }
        $this->tariffs ??= Tariff::where('is_active', true)->whereNotNull('diameter_from')->orderBy('diameter_from')->get();

        return $this->tariffs->first(fn (Tariff $t) => $diameter >= $t->diameter_from && $diameter <= ($t->diameter_to ?? $t->diameter_from));
    }

    /**
     * Строка расчёта для комплекта.
     *
     * @return array{tariff: Tariff, diameter: int, price: float, qty: int, months: int, amount: float}
     */
    public function line(string $tireSize, int $qty, int $months): array
    {
        $diameter = self::diameter($tireSize);
        $tariff = $this->tariffFor($diameter);
        if (! $tariff) {
            throw DomainError::invalid($diameter
                ? "Нет цены хранения для R{$diameter} — добавьте её в «Тарифах»"
                : 'Не удалось определить диаметр из размера шин', 'sets');
        }
        $price = (float) $tariff->price;

        return ['tariff' => $tariff, 'diameter' => $diameter, 'price' => $price, 'qty' => $qty, 'months' => $months,
            'amount' => round($price * $qty * $months, 2)];
    }

    public function validateMonths(int $months): int
    {
        $max = self::settings()['max_months'];
        if ($months < 1 || $months > $max) {
            throw DomainError::invalid("Срок хранения — от 1 до {$max} мес.", 'months');
        }

        return $months;
    }

    /**
     * Стоимость продления действующих комплектов договора на $months месяцев по текущему прайсу.
     *
     * @return list<array{set: WheelSet, tariff: Tariff, diameter: int, price: float, qty: int, months: int, amount: float}>
     */
    public function extensionLines(StorageContract $contract, int $months): array
    {
        return $contract->sets()->whereIn('status', SetStatus::activeValues())->get()
            ->map(fn (WheelSet $s) => ['set' => $s] + $this->line($s->tire_size, $s->qty, $months))
            ->values()->all();
    }

    /**
     * Плата за хранение после окончания срока и бесплатных дней — на дату $today.
     *
     * @return array{ends_at: ?string, free_until: ?string, free_days: int, days: int, rate: float, per: string,
     *               wheels: int, amount: float, charged: float, due: float}
     */
    public function overdue(StorageContract $contract, ?CarbonInterface $today = null): array
    {
        $s = self::settings();
        $today = Carbon::parse($today ?? now())->startOfDay();
        $ends = collect([$contract->storage_until, $contract->paid_until])->filter()->max();
        $wheels = (int) $contract->sets()->whereIn('status', SetStatus::activeValues())->sum('qty');
        $freeUntil = $ends ? Carbon::parse($ends)->addDays($s['free_days']) : null;
        $days = $freeUntil && $today->gt($freeUntil) ? (int) $freeUntil->diffInDays($today) : 0;
        $perDay = $s['overdue_per'] === 'wheel' ? $s['overdue_rate'] * $wheels : $s['overdue_rate'];
        $amount = round($days * $perDay, 2);
        $charged = (float) $contract->overdue_fee;

        return [
            'ends_at' => $ends ? Carbon::parse($ends)->toDateString() : null,
            'free_until' => $freeUntil?->toDateString(),
            'free_days' => $s['free_days'],
            'days' => $days,
            'rate' => $s['overdue_rate'],
            'per' => $s['overdue_per'],
            'wheels' => $wheels,
            'amount' => $amount,
            'charged' => $charged,
            'due' => max(0, round($amount - $charged, 2)),
        ];
    }
}
