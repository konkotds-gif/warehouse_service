<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\OutboxEvent;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** /health: БД, кэш, очередь, возраст последнего подтверждённого события 1С (техдок, раздел 10). */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [];
        try {
            DB::select('select 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'fail';
        }
        try {
            Cache::put('health', 1, 10);
            $checks['cache'] = Cache::get('health') === 1 ? 'ok' : 'fail';
        } catch (\Throwable $e) {
            $checks['cache'] = 'fail';
        }
        try {
            $checks['queue_failed_jobs'] = DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            $checks['queue_failed_jobs'] = null;
        }
        $minutes = (int) config('storage.onec.ack_alert_minutes', 30);
        $lastAck = OutboxEvent::whereNotNull('delivered_at')->max('delivered_at');
        $checks['onec'] = [
            'pending' => OutboxEvent::whereNull('delivered_at')->count(),
            'stale' => OutboxEvent::whereNull('delivered_at')->where('created_at', '<', now()->subMinutes($minutes))->count(),
            'last_ack_at' => $lastAck,
        ];
        try {
            $checks['scheduler_last_run_at'] = Setting::get('scheduler.last_run_at');
            $checks['car_catalog_last_sync'] = Setting::get('cars.last_sync');
        } catch (\Throwable) {
            $checks['scheduler_last_run_at'] = null;
        }
        $ok = $checks['database'] === 'ok' && $checks['cache'] === 'ok';

        return response()->json(['status' => $ok ? 'ok' : 'fail', 'checks' => $checks, 'time' => now()->toIso8601String()], $ok ? 200 : 503);
    }
}
