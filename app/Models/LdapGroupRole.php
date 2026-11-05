<?php

namespace App\Models;

use App\Domain\Users\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Сопоставление «группа AD → роль платформы (+ магазин/склад)». */
class LdapGroupRole extends Model
{
    protected $fillable = ['group_dn', 'group_name', 'role', 'location_id'];

    protected function casts(): array
    {
        return ['role' => Role::class];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
