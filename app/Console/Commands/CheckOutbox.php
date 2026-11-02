<?php

namespace App\Console\Commands;

use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckOutbox extends Command
{
    protected $signature = 'onec:check-outbox';

    protected $description = 'Проверить события 1С без подтверждения дольше порога и уведомить администратора';

    public function handle(): int
    {
        $minutes = (int) config('storage.onec.ack_alert_minutes', 30);
        $stale = OutboxEvent::whereNull('delivered_at')->where('created_at', '<', now()->subMinutes($minutes));
        $count = $stale->count();
        if ($count === 0) {
            $this->info('Зависших событий нет');

            return self::SUCCESS;
        }
        $oldest = $stale->min('id');
        $message = "Интеграция с 1С: {$count} событий без подтверждения дольше {$minutes} мин (самое старое — №{$oldest})";
        Log::warning($message);
        $this->warn($message);

        // Не чаще раза в час, чтобы не засыпать почту
        $email = config('storage.onec.alert_email');
        if ($email && Cache::add('onec-outbox-alert', true, now()->addHour())) {
            Mail::raw($message, fn ($m) => $m->to($email)->subject('Платформа хранения: обмен с 1С'));
        }

        return self::SUCCESS;
    }
}
