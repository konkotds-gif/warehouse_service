<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\OutboxEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Мониторинг обмена с 1С и ключи API (1С и онлайн-запись). */
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
            'keys' => ApiKey::orderBy('id')->get(['id', 'name', 'allowed_ips', 'scopes', 'is_active', 'last_used_at', 'created_at']),
            'scopes' => ApiKey::SCOPES,
            'stats' => [
                'pending' => OutboxEvent::whereNull('delivered_at')->count(),
                'stale' => OutboxEvent::whereNull('delivered_at')->where('created_at', '<', now()->subMinutes((int) config('storage.onec.ack_alert_minutes', 30)))->count(),
                'last_ack_at' => OutboxEvent::max('delivered_at'),
            ],
            'filter' => $request->query('filter'),
        ]);
    }

    public function storeKey(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'allowed_ips' => ['nullable', 'string', 'max:255'],
            'scope' => ['nullable', 'in:'.implode(',', array_keys(ApiKey::SCOPES))],
        ]);
        [, $plain] = ApiKey::generate($data['name'], $data['allowed_ips'] ?? null, $data['scope'] ?? 'onec');

        return back()->with('success', 'Ключ создан. Скопируйте его — повторно он не показывается.')->with('secret', $plain);
    }

    public function revokeKey(ApiKey $key): RedirectResponse
    {
        $key->update(['is_active' => false]);

        return back()->with('success', 'Ключ отключён');
    }
}
