<?php

namespace App\Domain\Support;

use Illuminate\Support\Facades\DB;

/**
 * Последовательные номера документов (договоры, перемещения) без дыр от откатов
 * неудачных транзакций не гарантируются, но уникальность — гарантируется блокировкой строки.
 */
final class NumberGenerator
{
    public function next(string $sequence, int $digits = 6, string $prefix = ''): string
    {
        return DB::transaction(function () use ($sequence, $digits, $prefix) {
            $row = DB::table('number_sequences')->where('name', $sequence)->lockForUpdate()->first();
            if ($row === null) {
                DB::table('number_sequences')->insertOrIgnore(['name' => $sequence, 'value' => 0]);
                $row = DB::table('number_sequences')->where('name', $sequence)->lockForUpdate()->first();
            }
            $value = (int) $row->value + 1;
            DB::table('number_sequences')->where('name', $sequence)->update(['value' => $value]);

            return $prefix.str_pad((string) $value, $digits, '0', STR_PAD_LEFT);
        });
    }

    /** Установить текущее значение (для миграции старых договоров). */
    public function ensureAtLeast(string $sequence, int $value): void
    {
        DB::table('number_sequences')->insertOrIgnore(['name' => $sequence, 'value' => 0]);
        DB::table('number_sequences')->where('name', $sequence)->where('value', '<', $value)->update(['value' => $value]);
    }

    public function contractNumber(): string
    {
        return $this->next('contract', (int) config('storage.contract_digits', 6));
    }

    public function transferNumber(): string
    {
        return $this->next('transfer', 5, 'П');
    }

    /** Документ пролонгации договора: ПР00001. */
    public function extensionNumber(): string
    {
        return $this->next('extension', 5, 'ПР');
    }

    public function tripNumber(): string
    {
        return $this->next('trip', 5, 'Р');
    }
}
