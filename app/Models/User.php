<?php

namespace App\Models;

use App\Domain\Users\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['login', 'name', 'email', 'password', 'role', 'location_id', 'is_active'];

    protected $attributes = ['auth_source' => 'local'];

    protected $hidden = ['password', 'remember_token', 'ldap_guid', 'ldap_dn'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'ldap_synced_at' => 'datetime',
            'ldap_denied_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    /** Учётная запись из домена (Active Directory): роль назначается по группам AD. */
    public function isLdap(): bool
    {
        return $this->auth_source === 'ldap';
    }

    /** Может работать: не заблокирован вручную и (для доменных) доступ не закрыт со стороны AD. */
    public function canSignIn(): bool
    {
        return $this->is_active && $this->ldap_denied_at === null;
    }

    public function hasAbility(string $ability): bool
    {
        return $this->canSignIn() && $this->role->can($ability);
    }
}
