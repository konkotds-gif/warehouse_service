<?php

namespace App\Models;

use App\Domain\Support\Normalizer;
use App\Models\Concerns\Audited;
use Illuminate\Database\Eloquent\Builder;
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

    /** Свои машины и машины, где клиент доверенное лицо. */
    public function accessibleVehiclesQuery(): Builder
    {
        return Vehicle::query()->where(fn ($q) => $q->where('client_id', $this->id)
            ->orWhereIn('id', VehiclePerson::vehicleIdsFor([$this->id])));
    }

    /** Может ли клиент пользоваться машиной (владелец или доверенное лицо). */
    public function canUseVehicle(Vehicle $vehicle): bool
    {
        return $vehicle->client_id === $this->id
            || VehiclePerson::active()->forVehicle($vehicle)->where('client_id', $this->id)->exists();
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(StorageContract::class)->latest('accepted_at');
    }
}
