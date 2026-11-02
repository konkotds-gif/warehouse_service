<?php

namespace App\Http\Middleware;

use App\Http\Api\ApiResponse;
use App\Http\Api\Xml;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Тело запроса в XML (1С 8.2) превращаем в обычные входные данные. */
class ParseXmlBody
{
    public function handle(Request $request, Closure $next): Response
    {
        if (str_contains(strtolower((string) $request->header('Content-Type')), 'xml') && $request->getContent() !== '') {
            try {
                $data = Xml::decode($request->getContent());
            } catch (\InvalidArgumentException $e) {
                return ApiResponse::error($e->getMessage(), 400, null, $request);
            }
            $request->merge($data);
        }

        return $next($request);
    }
}
