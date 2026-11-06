<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiResponse;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** X-Api-Key (или Authorization: Bearer) + белый список IP (техдок, 6) + область ключа (api.key:onec / api.key:booking). */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $plain = (string) ($request->header('X-Api-Key') ?: $request->bearerToken());
        $key = $plain !== '' ? ApiKey::findByPlain($plain) : null;
        if (! $key) {
            $this->deny($request, null, 'denied_key');

            return ApiResponse::error('Неверный или отсутствующий ключ X-Api-Key', 401, null, $request);
        }
        if (! $key->allowsIp($request->ip())) {
            $this->deny($request, $key, 'denied_ip');

            // IP в ответе — чтобы за NAT сразу было видно, какой адрес прописать ключу (Администрирование → Ключи API)
            return ApiResponse::error('Доступ с этого IP запрещён (IP: '.$request->ip().')', 403, null, $request);
        }
        if ($scope !== null && ! $key->hasScope($scope)) {
            $this->deny($request, $key, 'denied_scope');

            return ApiResponse::error('Ключ не предназначен для этого раздела API', 403, null, $request);
        }
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    /** Отказ — в журнал (Администрирование → Ключи API). Не чаще раза в минуту на IP и причину, чтобы перебор не раздувал журнал. */
    private function deny(Request $request, ?ApiKey $key, string $action): void
    {
        if (! Cache::add('api-deny:'.$action.':'.$request->ip().':'.$key?->id, 1, 60)) {
            return;
        }
        DB::table('api_access_log')->insert([
            'api_key_id' => $key?->id, 'action' => $action, 'ip' => $request->ip(),
            'plate' => null, 'phone' => null, 'found' => 0, 'created_at' => now(),
        ]);
    }
}
