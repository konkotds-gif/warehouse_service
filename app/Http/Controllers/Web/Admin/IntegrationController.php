<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\OutboxEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Мониторинг обмена с 1С (ключи API — Администрирование → Ключи API). */
class IntegrationController extends Controller
{
    public function index(Request $request): Response
    {
        $events = OutboxEvent::query()
            ->when($request->query('filter') === 'pending', fn ($q) => $q->whereNull('delivered_at'))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return Inertia::render('Admin/Integration', [
            'events' => $events->through(fn ($e) => [
                'id' => $e->id, 'type' => $e->event_type, 'created_at' => $e->created_at->toIso8601String(),
                'delivered_at' => $e->delivered_at?->toIso8601String(), 'attempts' => $e->attempts,
                'onec_doc_guid' => $e->onec_doc_guid, 'number' => $e->payload['contract_number'] ?? $e->payload['transfer_number'] ?? $e->payload['full_name'] ?? null,
                'payload' => $e->payload,
            ]),
            'stats' => [
                'pending' => OutboxEvent::whereNull('delivered_at')->count(),
                'stale' => OutboxEvent::whereNull('delivered_at')->where('created_at', '<', now()->subMinutes((int) config('storage.onec.ack_alert_minutes', 30)))->count(),
                'last_ack_at' => OutboxEvent::max('delivered_at'),
            ],
            'filter' => $request->query('filter'),
        ]);
    }
}
