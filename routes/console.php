<?php

use Illuminate\Support\Facades\Schedule;

// Ночью: пометка просроченных хранений
Schedule::command('storage:mark-overdue')->dailyAt('02:00')->withoutOverlapping();

// Каждые 10 минут: события 1С без подтверждения дольше порога → уведомление администратору
Schedule::command('onec:check-outbox')->everyTenMinutes()->withoutOverlapping();

// Очистка integration_inbox старше 90 дней
Schedule::command('onec:prune-inbox')->daily();
