<?php

namespace App\Console\Commands;

use App\Domain\Notifications\Sms;
use App\Models\SmsMessage;
use Illuminate\Console\Command;

/** Статусы доставки SMS из SMSC: опрос неокончательных за последние сутки (по расписанию — каждую минуту). */
class CheckSmsStatus extends Command
{
    protected $signature = 'sms:check-status';

    protected $description = 'Обновить статусы доставки отправленных SMS (SMSC)';

    public function handle(Sms $sms): int
    {
        $pending = SmsMessage::with('wheelSet')
            ->where('final', false)->whereNotNull('provider_id')
            ->where('created_at', '>=', now()->subDays(2))
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<', now()->subSeconds(50)))
            ->orderBy('id')->limit(100)->get();
        foreach ($pending as $message) {
            $sms->refreshStatus($message);
        }
        $this->info("Проверено SMS: {$pending->count()}");

        return self::SUCCESS;
    }
}
