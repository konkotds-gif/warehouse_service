<?php

namespace App\Models;

use App\Domain\Storage\Condition;
use App\Domain\Storage\GarageSetState;
use App\Domain\Storage\Season;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Комплект в гараже клиента: живёт дольше договора, из него подставляются данные при приёме.
 */
class GarageSet extends Model
{
    use HasUuids;

    protected $fillable = ['vehicle_id', 'title', 'season', 'qty', 'tire_size', 'with_rims', 'condition',
        'brand_model', 'damages', 'notes', 'state', 'last_contract_id', 'last_issued_at'];

    protected function casts(): array
    {
        return [
            'with_rims' => 'boolean',
            'damages' => 'array',
            'qty' => 'integer',
            'condition' => Condition::class,
            'season' => Season::class,
            'state' => GarageSetState::class,
            'last_issued_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function wheelSets(): HasMany
    {
        return $this->hasMany(WheelSet::class);
    }

    /**
     * Данные для подстановки при новом приёме (план, 6.1.1).
     * Если комплект был НОВЫЕ и с тех пор стоял на машине — подставляем БУ и просим подтвердить.
     *
     * @return array<string, mixed>
     */
    public function prefill(): array
    {
        $condition = $this->condition;
        $needsConfirm = false;
        if ($condition === Condition::New && in_array($this->state, [GarageSetState::OnVehicle, GarageSetState::WithClient], true)
            && $this->last_issued_at !== null) {
            $condition = Condition::Used;
            $needsConfirm = true;
        }

        return [
            'garage_set_id' => $this->id,
            'title' => $this->title,
            'qty' => $this->qty,
            'tire_size' => $this->tire_size,
            'with_rims' => $this->with_rims,
            'condition' => $condition->value,
            'condition_needs_confirm' => $needsConfirm,
            'season' => $this->season?->value,
            'brand_model' => $this->brand_model,
            'damages' => $condition === Condition::Used ? ($this->damages ?? []) : [],
            'notes' => $this->notes,
        ];
    }
}
