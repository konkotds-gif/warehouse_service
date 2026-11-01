<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Простой аудит правок справочников и договоров в таблицу audit_log (план, раздел 12).
 */
trait Audited
{
    protected static function bootAudited(): void
    {
        static::created(fn ($m) => $m->writeAudit('create', $m->getAttributes()));
        static::updated(function ($m) {
            $changes = $m->getChanges();
            unset($changes['updated_at']);
            if ($changes === []) {
                return;
            }
            $diff = [];
            foreach ($changes as $k => $v) {
                $diff[$k] = [$m->getOriginal($k) instanceof \UnitEnum ? $m->getOriginal($k)->value : $m->getOriginal($k), $v];
            }
            $m->writeAudit('update', $diff);
        });
        static::deleted(fn ($m) => $m->writeAudit('delete', null));
    }

    protected function writeAudit(string $action, ?array $diff): void
    {
        $safe = $diff === null ? null : array_map(
            fn ($v) => $v instanceof \DateTimeInterface ? $v->format(DATE_ATOM) : $v,
            $diff
        );

        DB::table('audit_log')->insert([
            'at' => now(),
            'user_id' => Auth::id(),
            'entity' => class_basename($this),
            'entity_id' => (string) $this->getKey(),
            'action' => $action,
            'diff' => $safe === null ? null : json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
        ]);
    }
}
