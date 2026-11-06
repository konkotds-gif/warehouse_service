<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\IpUtils;

/** Администрирование → Ключи API: ключи 1С и онлайн-записи, белые списки IP, журнал отказов и обращений. */
class ApiKeyController extends Controller
{
    private const ACTIONS = [
        'denied_ip' => 'IP не в списке', 'denied_scope' => 'Ключ не для этого раздела', 'denied_key' => 'Неверный ключ',
        'lookup' => 'Поиск хранения', 'garage_import' => 'Импорт гаража из записи',
    ];

    public function index(Request $request): Response
    {
        $denied = $request->query('log') !== 'all';
        $log = DB::table('api_access_log as l')->leftJoin('api_keys as k', 'k.id', '=', 'l.api_key_id')
            ->when($denied, fn ($q) => $q->where('l.action', 'like', 'denied_%'))
            ->orderByDesc('l.id')->limit(100)
            ->get(['l.id', 'l.api_key_id', 'k.name as key_name', 'l.action', 'l.ip', 'l.phone', 'l.plate', 'l.found', 'l.created_at']);

        return Inertia::render('Admin/ApiKeys', [
            'keys' => ApiKey::orderBy('id')->get(['id', 'name', 'allowed_ips', 'scopes', 'is_active', 'last_used_at', 'created_at']),
            'scopes' => ApiKey::SCOPES,
            'log' => $log->map(fn ($r) => [
                'id' => $r->id, 'key_id' => $r->api_key_id, 'key_name' => $r->key_name, 'action' => $r->action,
                'action_label' => self::ACTIONS[$r->action] ?? $r->action, 'denied' => str_starts_with($r->action, 'denied_'),
                'ip' => $r->ip, 'phone' => $r->phone, 'plate' => $r->plate, 'found' => $r->found,
                'created_at' => Carbon::parse($r->created_at)->toIso8601String(),
            ]),
            'logFilter' => $denied ? 'denied' : 'all',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        [$key, $plain] = ApiKey::generate($data['name'], $data['allowed_ips'], $data['scope']);

        return back()->with('success', 'Ключ «'.$key->name.'» создан. Скопируйте его — повторно он не показывается.')->with('secret', $plain);
    }

    public function update(Request $request, ApiKey $key): RedirectResponse
    {
        $data = $this->validated($request);
        $key->update(['name' => $data['name'], 'allowed_ips' => $data['allowed_ips'], 'scopes' => [$data['scope']]]);

        return back()->with('success', 'Ключ «'.$key->name.'» сохранён');
    }

    public function toggle(ApiKey $key): RedirectResponse
    {
        $key->update(['is_active' => ! $key->is_active]);

        return back()->with('success', $key->is_active ? 'Ключ включён' : 'Ключ отключён');
    }

    /** «Разрешить этот IP» из журнала отказов. */
    public function allowIp(ApiKey $key, Request $request): RedirectResponse
    {
        $ip = (string) $request->input('ip');
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages(['ip' => 'Неверный IP']);
        }
        $list = ApiKey::ipList($key->allowed_ips);
        if (! $list) {
            return back()->with('success', 'У ключа нет ограничения по IP — доступ и так разрешён с любого адреса');
        }
        if (! IpUtils::checkIp($ip, $list)) {
            $key->update(['allowed_ips' => implode(', ', [...$list, $ip])]);
        }

        return back()->with('success', 'IP '.$ip.' разрешён для ключа «'.$key->name.'»');
    }

    /** @return array{name: string, allowed_ips: ?string, scope: string} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'allowed_ips' => ['nullable', 'string', 'max:255'],
            'scope' => ['required', 'in:'.implode(',', array_keys(ApiKey::SCOPES))],
        ], [], ['name' => 'Название', 'allowed_ips' => 'IP', 'scope' => 'Для чего']);
        $ips = ApiKey::ipList($data['allowed_ips'] ?? null);
        foreach ($ips as $ip) {
            [$addr, $mask] = array_pad(explode('/', $ip, 2), 2, null);
            if (! filter_var($addr, FILTER_VALIDATE_IP) || ($mask !== null && (! ctype_digit($mask) || (int) $mask > (str_contains($addr, ':') ? 128 : 32)))) {
                throw ValidationException::withMessages(['allowed_ips' => 'Неверный адрес: '.$ip.'. Пример: 203.0.113.10, 10.0.0.0/24']);
            }
        }

        return ['name' => trim($data['name']), 'allowed_ips' => $ips ? implode(', ', $ips) : null, 'scope' => $data['scope']];
    }
}
