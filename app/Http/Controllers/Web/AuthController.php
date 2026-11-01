<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Вход по логину и паролю; блокировка после 5 неудачных попыток (техдок, раздел 8). */
class AuthController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['login' => ['required', 'string'], 'password' => ['required', 'string']]);
        $throttle = 'login:'.mb_strtolower($data['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttle) / 60);
            throw ValidationException::withMessages(['login' => "Слишком много попыток. Повторите через {$minutes} мин."]);
        }
        if (! Auth::attempt(['login' => $data['login'], 'password' => $data['password'], 'is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($throttle, 15 * 60);
            throw ValidationException::withMessages(['login' => 'Неверный логин или пароль']);
        }
        RateLimiter::clear($throttle);
        $request->session()->regenerate();
        /** @var User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->intended('/');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
