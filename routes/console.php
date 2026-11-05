<?php

use App\Models\Setting;
use Illuminate\Support\Facades\Schedule;

// Ночью: пометка просроченных хранений
Schedule::command('storage:mark-overdue')->dailyAt('02:00')->withoutOverlapping();

// Каждые 10 минут: события 1С без подтверждения дольше порога → уведомление администратору
Schedule::command('onec:check-outbox')->everyTenMinutes()->withoutOverlapping();

// Очистка integration_inbox старше 90 дней
Schedule::command('onec:prune-inbox')->daily();

// Ежедневно в 04:30 по местному времени: справочник марок и моделей авто из системы записи (304, если не менялся)
Schedule::command('cars:sync')->dailyAt('04:30')->timezone(config('app.display_timezone', 'UTC'))->withoutOverlapping();

// Отметка, что планировщик (cron schedule:run) работает — видна в «Настройках» и в /health
Schedule::call(fn () => Setting::put('scheduler.last_run_at', now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');

// Каждый час: сверка доменных пользователей с AD (роли по группам, отключённые и уволенные)
Schedule::command('ldap:sync')->hourly()->withoutOverlapping();
