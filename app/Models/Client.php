<?php

namespace App\Models;

use App\Domain\Support\Normalizer;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use Audited, HasUuids, SoftDeletes;

    protected $fillable = ['onec_guid', 'full_name', 'phone', 'email', 'consent_sms', 'consent_pd', 'notes'];

    protected function casts(): array
    {
        return ['consent_sms' => 'boolean', 'consent_pd' => 'boolean'];
    }

    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = Normalizer::phone($value);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(StorageContract::class)->latest('accepted_at');
    }
}
