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

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
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

    public function hasAbility(string $ability): bool
    {
        return $this->is_active && $this->role->can($ability);
    }
}
