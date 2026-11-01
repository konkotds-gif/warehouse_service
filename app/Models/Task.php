<?php

namespace App\Models;

use App\Domain\Warehouse\TaskStatus;
use App\Domain\Warehouse\TaskType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Задание сотруднику: разместить, отобрать, подготовить к выдаче, разобрать расхождение. */
class Task extends Model
{
    protected $fillable = ['type', 'wheel_set_id', 'location_id', 'due_date', 'assignee_id', 'status', 'title',
        'payload', 'created_by', 'completed_at'];

    protected function casts(): array
    {
        return [
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'due_date' => 'date',
            'payload' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function wheelSet(): BelongsTo
    {
        return $this->belongsTo(WheelSet::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
