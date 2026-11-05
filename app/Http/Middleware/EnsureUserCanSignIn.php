<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Завершает сеанс пользователя, которого заблокировали в платформе или которому закрыли доступ в AD
 * (уволен, отключён, убран из групп) — в том числе при входе с «Запомнить на этом устройстве».
 */
class EnsureUserCanSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = Auth::user();
        if ($user && ! $user->canSignIn()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $message = 'Доступ к платформе закрыт. Обратитесь к администратору.';
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => $message], 401);
            }

            return redirect('/login')->withErrors(['login' => $message]);
        }

        return $next($request);
    }
}
