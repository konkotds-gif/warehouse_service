<?php

namespace App\Providers;

use App\Domain\Ldap\LdapConnection;
use App\Domain\Ldap\NativeLdapConnection;
use App\Domain\Notifications\Sms;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Sms::class);
        $this->app->bind(LdapConnection::class, NativeLdapConnection::class);
    }

    public function boot(): void
    {
        // Права по ролям (план, раздел 7): Gate::allows('accept') и middleware can:accept
        Gate::before(fn (User $user, string $ability) => $user->hasAbility($ability) ? true : null);

        // Документация API (Scramble) — только администратор
        Gate::define('viewApiDocs', fn (User $user) => $user->isAdmin());
    }
}
