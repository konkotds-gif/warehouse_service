<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiResponse;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** X-Api-Key (или Authorization: Bearer) + белый список IP (техдок, 6) + область ключа (api.key:onec / api.key:booking). */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $plain = (string) ($request->header('X-Api-Key') ?: $request->bearerToken());
        $key = $plain !== '' ? ApiKey::findByPlain($plain) : null;
        if (! $key) {
            return ApiResponse::error('Неверный или отсутствующий ключ X-Api-Key', 401, null, $request);
        }
        if (! $key->allowsIp($request->ip())) {
            return ApiResponse::error('Доступ с этого IP запрещён', 403, null, $request);
        }
        if ($scope !== null && ! $key->hasScope($scope)) {
            return ApiResponse::error('Ключ не предназначен для этого раздела API', 403, null, $request);
        }
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
