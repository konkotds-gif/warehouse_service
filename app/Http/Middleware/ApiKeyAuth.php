<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiResponse;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** X-Api-Key + белый список IP (техдок, 6). */
class ApiKeyAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->header('X-Api-Key');
        $key = $plain !== '' ? ApiKey::findByPlain($plain) : null;
        if (! $key) {
            return ApiResponse::error('Неверный или отсутствующий ключ X-Api-Key', 401, null, $request);
        }
        if (! $key->allowsIp($request->ip())) {
            return ApiResponse::error('Доступ с этого IP запрещён', 403, null, $request);
        }
        if ($key->last_used_at === null || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }
        $request->attributes->set('api_key', $key);

        return $next($request);
    }
}
