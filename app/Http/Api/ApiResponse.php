<?php

namespace App\Http\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Ответ API в JSON или XML — по заголовку Accept (план, 9.1).
 */
final class ApiResponse
{
    public static function wantsXml(Request $request): bool
    {
        $accept = strtolower((string) $request->header('Accept'));

        return str_contains($accept, 'xml') && ! str_contains($accept, 'json');
    }

    public static function make(mixed $data, int $status = 200, ?Request $request = null, string $root = 'response'): Response
    {
        $request ??= request();
        if (self::wantsXml($request)) {
            return new Response(Xml::encode($data, $root), $status, ['Content-Type' => 'application/xml; charset=UTF-8']);
        }

        return new Response(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            $status, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    public static function error(string $message, int $status, ?array $errors = null, ?Request $request = null): Response
    {
        $body = ['message' => $message];
        if ($errors) {
            $body['errors'] = $errors;
        }

        return self::make($body, $status, $request, 'error');
    }
}
