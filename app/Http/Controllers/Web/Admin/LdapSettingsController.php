<?php

namespace App\Http\Controllers\Web\Admin;

use App\Domain\Ldap\LdapAccess;
use App\Domain\Ldap\LdapAuthFailed;
use App\Domain\Ldap\LdapConfig;
use App\Domain\Ldap\LdapConnection;
use App\Domain\Ldap\LdapDirectory;
use App\Domain\Ldap\LdapException;
use App\Domain\Users\Role;
use App\Http\Controllers\Controller;
use App\Models\LdapGroupRole;
use App\Models\Location;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** «Настройки → Вход через Active Directory»: подключение, проверка, сопоставление групп и ролей. */
class LdapSettingsController extends Controller
{
    /** Данные раздела для страницы настроек. */
    public static function props(): array
    {
        $c = LdapConfig::load();

        return [
            'extension' => LdapConfig::extensionLoaded(),
            'config' => [
                'enabled' => $c->enabled,
                'hosts' => implode(', ', $c->hosts),
                'port' => $c->port,
                'encryption' => $c->encryption,
                'verify_cert' => $c->verifyCert,
                'ca_cert' => $c->caCert,
                'base_dn' => $c->baseDn,
                'bind_user' => $c->bindUser,
                'domain' => $c->domain,
                'timeout' => $c->timeout,
            ],
            'password_set' => filled($c->bindPassword),
            'complete' => $c->complete(),
            'mappings' => LdapGroupRole::with('location')->get()
                ->sortBy([fn ($a, $b) => LdapAccess::RANK[$b->role->value] <=> LdapAccess::RANK[$a->role->value], fn ($a, $b) => strcasecmp($a->group_name, $b->group_name)])
                ->values()->map(fn (LdapGroupRole $m) => [
                    'id' => $m->id, 'group_dn' => $m->group_dn, 'group_name' => $m->group_name,
                    'role' => $m->role->value, 'role_label' => $m->role->label(),
                    'location_id' => $m->location_id, 'location' => $m->location ? $m->location->code.' · '.$m->location->name : null,
                ]),
            'users' => [
                'total' => User::where('auth_source', 'ldap')->count(),
                'denied' => User::where('auth_source', 'ldap')->whereNotNull('ldap_denied_at')->count(),
            ],
            'last_sync' => Setting::get(LdapConfig::LAST_SYNC),
            'roles' => collect(Role::cases())->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()]),
            'locations' => Location::orderBy('code')->get(['id', 'code', 'name']),
        ];
    }

    public function update(Request $request): RedirectResponse
    {
        $config = $this->configFromRequest($request);
        if ($request->boolean('remove_password')) {
            Setting::putSecret(LdapConfig::PASSWORD, null);
            $config->bindPassword = null;
        } elseif (filled($request->input('bind_password'))) {
            Setting::putSecret(LdapConfig::PASSWORD, (string) $request->input('bind_password'));
        }
        if ($config->enabled && ! $config->complete()) {
            throw ValidationException::withMessages(['enabled' => 'Чтобы включить вход через AD, заполните контроллер домена, base DN, сервисную учётную запись и её пароль.']);
        }
        if ($config->enabled && ! LdapConfig::extensionLoaded()) {
            throw ValidationException::withMessages(['enabled' => 'На сервере не установлено PHP-расширение ldap — см. docs/INSTALL.md.']);
        }
        $config->save();

        return back()->with('success', 'Настройки входа через AD сохранены');
    }

    /** Проверить подключение с введёнными в форме значениями (пароль — введённый или сохранённый). */
    public function test(Request $request, LdapConnection $connection): JsonResponse
    {
        try {
            $message = (new LdapDirectory($connection, $this->configFromRequest($request)))->test();
        } catch (LdapException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'message' => $message]);
    }

    /** Проверить пользователя: найден ли, в каких группах, какая роль получится. С паролем — и сам вход. */
    public function testUser(Request $request, LdapConnection $connection, LdapAccess $access): JsonResponse
    {
        $data = $request->validate(['login' => ['required', 'string', 'max:255'], 'password' => ['nullable', 'string']]);
        $directory = new LdapDirectory($connection, $this->configFromRequest($request));
        try {
            $user = filled($data['password'] ?? null)
                ? $directory->authenticate($data['login'], $data['password'])
                : $directory->lookup($data['login']);
        } catch (LdapAuthFailed|LdapException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }
        if (! $user) {
            return response()->json(['ok' => false, 'message' => 'Учётная запись «'.LdapDirectory::normalizeLogin($data['login']).'» в AD не найдена (в пределах base DN).']);
        }
        $mapping = $access->resolve($user);
        $mapped = LdapGroupRole::all()->keyBy(fn ($m) => mb_strtolower($m->group_dn));
        $local = User::where('login', $user->login)->where('auth_source', 'local')->exists();

        $problems = array_values(array_filter([
            $user->disabled ? 'учётная запись отключена в домене' : null,
            $user->expired ? 'срок действия учётной записи истёк' : null,
            ! $mapping ? 'не входит ни в одну сопоставленную группу' : null,
            $local ? 'в платформе есть локальный пользователь с тем же логином — он будет входить по локальному паролю' : null,
        ]));

        return response()->json([
            'ok' => $problems === [],
            'message' => $problems ? 'Вход будет невозможен или пойдёт не через AD: '.implode('; ', $problems).'.'
                : (filled($data['password'] ?? null) ? 'Пароль верный, вход разрешён.' : 'Учётная запись найдена, вход разрешён.'),
            'user' => ['login' => $user->login, 'name' => $user->name, 'email' => $user->email, 'dn' => $user->dn],
            'groups' => collect($user->groups)->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->map(fn ($g) => [
                'name' => $g['name'], 'dn' => $g['dn'], 'role' => $mapped->get(mb_strtolower($g['dn']))?->role->label(),
            ]),
            'role' => $mapping?->role->label(),
            'location' => $mapping?->location?->code,
        ]);
    }

    public function groups(Request $request, LdapDirectory $directory): JsonResponse
    {
        try {
            return response()->json(['data' => $directory->searchGroups((string) $request->query('q', ''))]);
        } catch (LdapException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function storeMapping(Request $request): RedirectResponse
    {
        LdapGroupRole::create($this->mappingData($request, null));

        return back()->with('success', 'Сопоставление добавлено. Роль применится при следующем входе пользователей группы.');
    }

    public function updateMapping(Request $request, LdapGroupRole $mapping): RedirectResponse
    {
        $mapping->update($this->mappingData($request, $mapping));

        return back()->with('success', 'Сопоставление сохранено');
    }

    public function destroyMapping(LdapGroupRole $mapping): RedirectResponse
    {
        $mapping->delete();

        return back()->with('success', 'Сопоставление удалено');
    }

    public function sync(): RedirectResponse
    {
        $code = Artisan::call('ldap:sync');
        $last = Setting::get(LdapConfig::LAST_SYNC);

        return back()->with($code === 0 ? 'success' : 'error', $last['message'] ?? trim(Artisan::output()));
    }

    private function mappingData(Request $request, ?LdapGroupRole $mapping): array
    {
        return $request->validate([
            'group_dn' => ['required', 'string', 'max:500', Rule::unique('ldap_group_roles', 'group_dn')->ignore($mapping?->id)],
            'group_name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
        ], ['group_dn.unique' => 'Для этой группы сопоставление уже есть']);
    }

    private function configFromRequest(Request $request): LdapConfig
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'hosts' => ['required', 'string', 'max:500'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', Rule::in(LdapConfig::ENCRYPTIONS)],
            'verify_cert' => ['boolean'],
            'ca_cert' => ['nullable', 'string', 'max:20000', 'regex:/-----BEGIN CERTIFICATE-----/'],
            'base_dn' => ['required', 'string', 'max:500', 'regex:/^\s*(DC|OU|CN)=/i'],
            'bind_user' => ['required', 'string', 'max:255'],
            'bind_password' => ['nullable', 'string', 'max:255'],
            'domain' => ['nullable', 'string', 'max:100'],
            'timeout' => ['nullable', 'integer', 'between:1,30'],
        ], [
            'ca_cert.regex' => 'Вставьте сертификат в формате PEM (-----BEGIN CERTIFICATE-----…)',
            'base_dn.regex' => 'Base DN вида DC=corp,DC=local или OU=Сотрудники,DC=corp,DC=local',
        ]);

        $hosts = collect(preg_split('/[\s,;]+/', $data['hosts']))->map(fn ($h) => preg_replace('#^ldaps?://#i', '', rtrim(trim($h), '/')))->filter()->values();
        foreach ($hosts as $host) {
            if (! preg_match('/^[A-Za-z0-9.\-]+$/', $host)) {
                throw ValidationException::withMessages(['hosts' => "Некорректное имя контроллера домена: {$host}. Порт указывается отдельно."]);
            }
        }

        return new LdapConfig(
            enabled: (bool) ($data['enabled'] ?? false),
            hosts: $hosts->all(),
            port: (int) $data['port'],
            encryption: $data['encryption'],
            verifyCert: (bool) ($data['verify_cert'] ?? true),
            caCert: filled($data['ca_cert'] ?? null) ? trim($data['ca_cert']) : null,
            baseDn: trim($data['base_dn']),
            bindUser: trim($data['bind_user']),
            bindPassword: filled($data['bind_password'] ?? null) ? $data['bind_password'] : Setting::getSecret(LdapConfig::PASSWORD),
            domain: trim((string) ($data['domain'] ?? '')),
            timeout: (int) ($data['timeout'] ?? 5),
        );
    }
}
