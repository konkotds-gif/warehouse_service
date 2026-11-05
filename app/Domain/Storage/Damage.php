<?php

namespace App\Domain\Storage;

/** Типовые повреждения БУ-комплекта, отмечаются чекбоксами при приёме. */
enum Damage: string
{
    case Scratches = 'scratches';
    case Scuffs = 'scuffs';
    case Cuts = 'cuts';
    case Bulge = 'bulge';
    case Repairs = 'repairs';
    case Cracks = 'cracks';
    case UnevenWear = 'uneven_wear';
    case LowTread = 'low_tread';
    case MissingStuds = 'missing_studs';
    case BeadDamage = 'bead_damage';
    case RimCurb = 'rim_curb';
    case RimBent = 'rim_bent';
    case RimCorrosion = 'rim_corrosion';
    case RimPaint = 'rim_paint';

    public function label(): string
    {
        return match ($this) {
            self::Scratches => 'Царапины',
            self::Scuffs => 'Потёртости',
            self::Cuts => 'Порезы боковины',
            self::Bulge => 'Грыжи',
            self::Repairs => 'Проколы / латки',
            self::Cracks => 'Трещины, старение резины',
            self::UnevenWear => 'Неравномерный износ',
            self::LowTread => 'Сильный износ протектора',
            self::MissingStuds => 'Выпавшие шипы',
            self::BeadDamage => 'Повреждение борта шины',
            self::RimCurb => 'Бордюрные сколы диска',
            self::RimBent => 'Деформация диска',
            self::RimCorrosion => 'Коррозия диска',
            self::RimPaint => 'Сколы краски диска',
        };
    }

    /** Повреждения диска показываются только для комплекта с дисками. */
    public function isRim(): bool
    {
        return str_starts_with($this->value, 'rim_');
    }

    /** Отмечаются автоматически при выборе «БУ». */
    public function isDefault(): bool
    {
        return $this === self::Scratches || $this === self::Scuffs;
    }

    /** @return list<array{value: string, label: string, rim: bool, default: bool}> */
    public static function dictionary(): array
    {
        return array_map(fn (self $d) => ['value' => $d->value, 'label' => $d->label(), 'rim' => $d->isRim(), 'default' => $d->isDefault()], self::cases());
    }

    /**
     * Чистит список из формы: только известные коды, без дублей, в порядке справочника.
     *
     * @return list<string>
     */
    public static function normalize(mixed $values): array
    {
        $values = is_array($values) ? array_map('strval', $values) : [];

        return array_values(array_map(fn (self $d) => $d->value,
            array_filter(self::cases(), fn (self $d) => in_array($d->value, $values, true))));
    }

    /** @param  list<string>|null  $values */
    public static function labels(?array $values): array
    {
        return array_values(array_filter(array_map(fn ($v) => self::tryFrom((string) $v)?->label(), $values ?? [])));
    }
}
