<?php

namespace App\Http\Controllers\Web;

use App\Domain\Ldap\LdapAccess;
use App\Domain\Ldap\LdapAuthFailed;
use App\Domain\Ldap\LdapDirectory;
use App\Domain\Ldap\LdapException;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Вход по логину и паролю; блокировка после 5 неудачных попыток (техдок, раздел 8).
 *
 * Локальный пользователь (созданный в платформе) всегда входит по своему паролю, AD не опрашивается —
 * так локальный администратор остаётся запасным входом. Остальные логины проверяются в Active Directory,
 * роль назначается по группам AD при каждом входе.
 */
class AuthController extends Controller
{
    public function show(LdapDirectory $ldap): Response
    {
        return Inertia::render('Auth/Login', [
            'ldap' => $ldap->enabled() ? ['domain' => $ldap->config()->domain ?: null] : null,
        ]);
    }

    public function login(Request $request, LdapDirectory $ldap, LdapAccess $access): RedirectResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']]);
        $login = LdapDirectory::normalizeLogin($data['login']);
        $throttle = 'login:'.mb_strtolower($login).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttle, 5)) {
            $minutes = (int) ceil(RateLimiter::availableIn($throttle) / 60);
            throw ValidationException::withMessages(['login' => "Слишком много попыток. Повторите через {$minutes} мин."]);
        }
        $fail = function (string $message, bool $count = true) use ($throttle) {
            if ($count) {
                RateLimiter::hit($throttle, 15 * 60);
            }
            throw ValidationException::withMessages(['login' => $message]);
        };

        $local = $login !== '' ? User::where('login', $login)->where('auth_source', 'local')->first() : null;
        if ($local || ! $ldap->enabled()) {
            if (! Auth::attempt(['login' => $login, 'password' => $data['password'], 'auth_source' => 'local', 'is_active' => true], $request->boolean('remember'))) {
                $fail('Неверный логин или пароль');
            }
        } else {
            try {
                $ldapUser = $ldap->authenticate($login, $data['password']);
            } catch (LdapAuthFailed $e) {
                $fail($e->getMessage(), $e->countsAsAttempt);
            } catch (LdapException $e) {
                Log::warning('Вход через AD: '.$e->getMessage());
                $fail('Контроллер домена недоступен. Попробуйте позже; администратор может войти локальной учётной записью.', false);
            }
            $mapping = $access->resolve($ldapUser);
            if (! $mapping) {
                $existing = User::where('ldap_guid', $ldapUser->guid)->first();
                if ($existing) {
                    $access->deny($existing);
                }
                $fail('Ваша учётная запись не входит ни в одну группу AD с доступом к платформе. Обратитесь к администратору.', false);
            }
            $user = $access->provision($ldapUser, $mapping);
            if (! $user->is_active) {
                $fail('Учётная запись заблокирована в платформе. Обратитесь к администратору.', false);
            }
            Auth::login($user, $request->boolean('remember'));
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
