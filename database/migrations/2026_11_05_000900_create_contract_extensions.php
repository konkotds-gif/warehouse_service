<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Документ пролонгации (продления) договора хранения: номер ПР00001, срок, новая дата окончания,
 * строки стоимости по прайсу диаметров. Прежние продления восстанавливаются из событий contract.extended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_extensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 32)->unique();
            $table->foreignUuid('contract_id')->constrained('storage_contracts');
            $table->unsignedSmallInteger('seq'); // порядковый номер продления в договоре
            $table->unsignedSmallInteger('months');
            $table->date('period_from'); // продление считается с этой даты
            $table->date('previous_until')->nullable(); // срок хранения до продления
            $table->date('storage_until'); // новая дата окончания хранения
            $table->decimal('amount', 12, 2)->default(0);
            $table->json('lines')->nullable(); // строки услуги по комплектам
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('outbox_event_id')->nullable();
            $table->uuid('onec_doc_guid')->nullable();
            $table->timestamp('extended_at');
            $table->timestamps();
            $table->unique(['contract_id', 'seq']);
            $table->index('outbox_event_id');
        });

        $this->backfill();
    }

    /** Продления, сделанные до появления документа, — из очереди событий для 1С. */
    private function backfill(): void
    {
        $events = DB::table('integration_outbox')->where('event_type', 'contract.extended')->orderBy('id')->get();
        $n = 0;
        $seq = [];
        foreach ($events as $e) {
            $p = json_decode($e->payload, true) ?: [];
            $contractId = $p['contract_id'] ?? null;
            if (! $contractId || ! DB::table('storage_contracts')->where('id', $contractId)->exists()) {
                continue;
            }
            $months = (int) ($p['extended_months'] ?? 0);
            $until = ! empty($p['storage_until']) ? Carbon::parse($p['storage_until']) : null;
            if ($months < 1 || ! $until) {
                continue;
            }
            $from = $until->copy()->subMonthsNoOverflow($months);
            $seq[$contractId] = ($seq[$contractId] ?? 0) + 1;
            $n++;
            DB::table('contract_extensions')->insert([
                'id' => (string) Str::uuid(),
                'number' => 'ПР'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
                'contract_id' => $contractId,
                'seq' => $seq[$contractId],
                'months' => $months,
                'period_from' => $from->toDateString(),
                'previous_until' => $from->toDateString(),
                'storage_until' => $until->toDateString(),
                'amount' => (float) ($p['extra_amount'] ?? 0),
                'lines' => json_encode(array_map(fn ($l) => [
                    'qr_code' => $l['qr_code'] ?? null,
                    'tire_size' => null,
                    'diameter' => $l['diameter'] ?? null,
                    'service_code' => $l['service_code'] ?? null,
                    'service_onec_guid' => $l['service_onec_guid'] ?? null,
                    'qty' => $l['qty'] ?? null,
                    'months' => $l['months'] ?? $months,
                    'price' => $l['price'] ?? null,
                    'amount' => $l['amount'] ?? null,
                ], $p['extension_lines'] ?? []), JSON_UNESCAPED_UNICODE),
                'outbox_event_id' => $e->id,
                'onec_doc_guid' => $e->onec_doc_guid,
                'extended_at' => $e->created_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        if ($n > 0) {
            DB::table('number_sequences')->insertOrIgnore(['name' => 'extension', 'value' => 0]);
            DB::table('number_sequences')->where('name', 'extension')->where('value', '<', $n)->update(['value' => $n]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_extensions');
    }
};
