<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiResponse;
use App\Models\InboxRecord;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Идемпотентность POST/PUT от 1С (план, 9.3): повтор с тем же Idempotency-Key возвращает прежний ответ,
 * тот же ключ с другим телом — 409.
 */
class Idempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || ! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }
        if (strlen($key) > 120) {
            return ApiResponse::error('Idempotency-Key длиннее 120 символов', 400, null, $request);
        }
        $hash = hash('sha256', $request->method().' '.$request->path().' '.$request->getContent());

        $existing = InboxRecord::where('idempotency_key', $key)->first();
        if ($existing) {
            if (! hash_equals($existing->request_hash, $hash)) {
                return ApiResponse::error('Idempotency-Key уже использован с другим телом запроса', 409, null, $request);
            }

            return new IlluminateResponse($existing->response_body, $existing->response_status, [
                'Content-Type' => $existing->response_type,
                'Idempotent-Replay' => 'true',
            ]);
        }

        $response = $next($request);

        if ($response->getStatusCode() < 500) {
            try {
                InboxRecord::create([
                    'idempotency_key' => $key,
                    'request_hash' => $hash,
                    'method' => $request->method(),
                    'path' => mb_substr($request->path(), 0, 255),
                    'response_status' => $response->getStatusCode(),
                    'response_body' => (string) $response->getContent(),
                    'response_type' => (string) ($response->headers->get('Content-Type') ?? 'application/json'),
                    'processed_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Параллельный повтор с тем же ключом уже сохранён — ответ тот же
            }
        }

        return $response;
    }
}
