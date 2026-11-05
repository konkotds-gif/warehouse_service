<?php

namespace App\Http\Presenters;

use App\Domain\Storage\Damage;
use App\Models\Client;
use App\Models\GarageSet;
use App\Models\Location;
use App\Models\Movement;
use App\Models\StorageContract;
use App\Models\Task;
use App\Models\Transfer;
use App\Models\Vehicle;
use App\Models\WheelSet;

/**
 * Преобразование моделей в данные для страниц Vue.
 */
final class Present
{
    public static function location(?Location $l): ?array
    {
        return $l ? [
            'id' => $l->id, 'code' => $l->code, 'name' => $l->name, 'type' => $l->type->value,
            'type_label' => $l->type->label(), 'label' => $l->label(), 'address' => $l->address,
            'is_active' => $l->is_active, 'onec_guid' => $l->onec_guid,
        ] : null;
    }

    public static function set(WheelSet $s): array
    {
        return [
            'id' => $s->id,
            'qr_code' => $s->qr_code,
            'contract_id' => $s->contract_id,
            'contract_number' => $s->contract?->number,
            'qty' => $s->qty,
            'tire_size' => $s->tire_size,
            'with_rims' => $s->with_rims,
            'condition' => $s->condition->value,
            'condition_label' => $s->condition->label(),
            'season' => $s->season?->value,
            'season_label' => $s->season?->label(),
            'brand_model' => $s->brand_model,
            'damages' => $s->damages ?? [],
            'damages_labels' => Damage::labels($s->damages),
            'notes' => $s->notes,
            'status' => $s->status->value,
            'status_label' => $s->status->label(),
            'address' => $s->currentAddress(),
            'location_id' => $s->current_location_id,
            'location_code' => $s->location?->code,
            'issue_store' => $s->issueStore?->code,
            'issue_store_id' => $s->issue_store_id,
            'issue_due_date' => $s->issue_due_date?->toDateString(),
            'garage_set_id' => $s->garage_set_id,
            'title' => $s->title(),
            'plate' => $s->contract?->vehicle?->plate,
        ];
    }

    public static function contract(StorageContract $c, bool $detail = false): array
    {
        $data = [
            'id' => $c->id,
            'number' => $c->number,
            'status' => $c->status->value,
            'status_label' => $c->status->label(),
            'client' => $c->client ? ['id' => $c->client->id, 'full_name' => $c->client->full_name, 'phone' => $c->client->phone] : null,
            'vehicle' => $c->vehicle ? self::vehicle($c->vehicle) : null,
            'store' => self::location($c->store),
            'accepted_at' => $c->accepted_at?->toIso8601String(),
            'storage_until' => $c->storage_until?->toDateString(),
            'paid_until' => $c->paid_until?->toDateString(),
            'amount' => (float) $c->amount,
            'debt' => (float) $c->debt,
            'debt_updated_at' => $c->debt_updated_at?->toIso8601String(),
            'onec_doc_guid' => $c->onec_doc_guid,
            'sets' => $c->relationLoaded('sets') ? $c->sets->map(fn ($s) => self::set($s))->all() : [],
        ];
        if ($detail) {
            $data += [
                'tariff' => $c->tariff ? ['id' => $c->tariff->id, 'name' => $c->tariff->name, 'price' => (float) $c->tariff->price] : null,
                'accepted_by' => $c->acceptedBy?->name,
                'notes' => $c->notes,
                'closed_at' => $c->closed_at?->toIso8601String(),
                'payments' => $c->payments->map(fn ($p) => [
                    'amount' => (float) $p->amount, 'paid_until' => $p->paid_until?->toDateString(), 'paid_at' => $p->paid_at->toIso8601String(),
                ])->all(),
            ];
        }

        return $data;
    }

    public static function vehicle(Vehicle $v): array
    {
        return ['id' => $v->id, 'plate' => $v->plate, 'make' => $v->make, 'model' => $v->model, 'title' => $v->title()];
    }

    public static function client(Client $c): array
    {
        return [
            'id' => $c->id, 'full_name' => $c->full_name, 'phone' => $c->phone, 'email' => $c->email,
            'consent_sms' => $c->consent_sms, 'consent_pd' => $c->consent_pd, 'onec_guid' => $c->onec_guid, 'notes' => $c->notes,
        ];
    }

    public static function garageSet(GarageSet $g): array
    {
        $active = $g->relationLoaded('wheelSets') ? $g->wheelSets->first(fn ($s) => $s->status->isActive()) : null;

        return [
            'id' => $g->id, 'vehicle_id' => $g->vehicle_id, 'title' => $g->title,
            'season' => $g->season?->value, 'season_label' => $g->season?->label(),
            'qty' => $g->qty, 'tire_size' => $g->tire_size, 'with_rims' => $g->with_rims,
            'condition' => $g->condition->value, 'condition_label' => $g->condition->label(),
            'brand_model' => $g->brand_model, 'notes' => $g->notes,
            'damages' => $g->damages ?? [], 'damages_labels' => Damage::labels($g->damages),
            'state' => $g->state->value, 'state_label' => $g->state->label(),
            'active_set' => $active ? ['id' => $active->id, 'qr_code' => $active->qr_code, 'contract_id' => $active->contract_id,
                'status_label' => $active->status->label(), 'status' => $active->status->value, 'address' => $active->currentAddress()] : null,
            'prefill' => $g->prefill(),
        ];
    }

    /** Гараж: машины клиента с закреплёнными комплектами. */
    public static function garage(Client $client): array
    {
        $vehicles = $client->vehicles()->with(['garageSets' => fn ($q) => $q->orderBy('created_at'),
            'garageSets.wheelSets.cell', 'garageSets.wheelSets.location', 'garageSets.wheelSets.transfer'])->get();

        return $vehicles->map(fn (Vehicle $v) => self::vehicle($v) + [
            'garage_sets' => $v->garageSets->map(fn ($g) => self::garageSet($g))->all(),
        ])->all();
    }

    public static function movement(Movement $m): array
    {
        return [
            'id' => $m->id, 'at' => $m->at->toIso8601String(), 'action' => $m->action,
            'action_label' => Movement::actionLabel($m->action), 'from_address' => $m->from_address,
            'to_address' => $m->to_address, 'from_status' => $m->from_status, 'to_status' => $m->to_status,
            'user' => $m->user?->name, 'qty' => $m->qty, 'device' => $m->device, 'note' => $m->note,
            'qr_code' => $m->wheelSet?->qr_code,
        ];
    }

    public static function transfer(Transfer $t, bool $lines = false): array
    {
        $data = [
            'id' => $t->id, 'number' => $t->number, 'status' => $t->status->value, 'status_label' => $t->status->label(),
            'purpose' => $t->purpose, 'from' => self::location($t->from), 'to' => self::location($t->to),
            'driver' => $t->driver, 'created_at' => $t->created_at?->toIso8601String(),
            'shipped_at' => $t->shipped_at?->toIso8601String(), 'received_at' => $t->received_at?->toIso8601String(),
            'lines_count' => $t->lines_count ?? ($t->relationLoaded('lines') ? $t->lines->count() : null),
        ];
        if ($lines) {
            $data['lines'] = $t->lines->map(fn ($l) => [
                'id' => $l->id, 'scanned_out' => $l->scanned_out, 'scanned_in' => $l->scanned_in,
                'qty_out' => $l->qty_out, 'qty_in' => $l->qty_in, 'set' => self::set($l->wheelSet),
            ])->all();
        }

        return $data;
    }

    public static function task(Task $t): array
    {
        return [
            'id' => $t->id, 'type' => $t->type->value, 'type_label' => $t->type->label(), 'title' => $t->title,
            'status' => $t->status->value, 'due_date' => $t->due_date?->toDateString(),
            'location' => $t->location?->code, 'set' => $t->wheelSet ? ['id' => $t->wheelSet->id, 'qr_code' => $t->wheelSet->qr_code,
                'contract_id' => $t->wheelSet->contract_id, 'address' => $t->wheelSet->currentAddress()] : null,
            'payload' => $t->payload, 'assignee' => $t->assignee?->name,
            'overdue' => $t->status->value === 'open' && $t->due_date && $t->due_date->lt(now()->startOfDay()),
        ];
    }
}
