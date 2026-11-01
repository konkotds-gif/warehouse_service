<?php

namespace App\Domain\Storage;

use App\Domain\Support\DomainError;
use App\Models\StorageContract;
use App\Models\WheelSet;
use Illuminate\Support\Collection;

/**
 * Поиск по содержимому QR бирки (техдок, 3.2): сначала wheel_set.qr_code, затем номер договора.
 * Так работают и старые бирки без суффикса, и бирки с суффиксом «/2».
 */
final class ScanResolver
{
    public static function clean(string $code): string
    {
        $code = trim($code);
        // Допускаем QR со ссылкой вида https://…/s/000123 или префиксом «WS:»
        if (preg_match('~(?:/s/|^WS:)([0-9A-Za-zА-Яа-я/\-]+)$~u', $code, $m)) {
            $code = $m[1];
        }

        return $code;
    }

    /** @return Collection<int, WheelSet> */
    public function sets(string $code): Collection
    {
        $code = self::clean($code);
        if ($code === '') {
            return collect();
        }
        $set = WheelSet::where('qr_code', $code)->first();
        if ($set) {
            return collect([$set]);
        }
        $contract = StorageContract::where('number', $code)->first();

        return $contract ? $contract->sets()->get() : collect();
    }

    /** Ровно один активный комплект по скану (если по договору их несколько — уточнить). */
    public function oneActive(string $code, ?callable $filter = null): WheelSet
    {
        $sets = $this->sets($code)->filter(fn (WheelSet $s) => $s->status->isActive());
        if ($filter) {
            $sets = $sets->filter($filter);
        }
        if ($sets->isEmpty()) {
            throw DomainError::notFound("Комплект по коду «{$code}» не найден или уже выдан");
        }
        if ($sets->count() > 1) {
            throw DomainError::invalid('По договору несколько комплектов: отсканируйте бирку с суффиксом (например '.$sets->first()->qr_code.'/2) или выберите комплект', 'qr');
        }

        return $sets->first();
    }

    public function contract(string $code): ?StorageContract
    {
        $code = self::clean($code);
        $set = WheelSet::where('qr_code', $code)->first();

        return $set?->contract ?? StorageContract::where('number', $code)->first();
    }
}
