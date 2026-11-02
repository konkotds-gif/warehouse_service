<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Интеграция с 1С (outbox/inbox, ключи API, оплаты), инвентаризация, аудит.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 40);
            $table->json('payload');
            $table->timestamp('created_at', 3);
            $table->timestamp('delivered_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('onec_doc_guid')->nullable();
            $table->string('ack_note')->nullable();
            $table->index('delivered_at');
        });

        Schema::create('integration_inbox', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 120)->unique();
            $table->string('request_hash', 64);
            $table->string('method', 8);
            $table->string('path');
            $table->unsignedSmallInteger('response_status');
            $table->longText('response_body');
            $table->string('response_type', 64);
            $table->timestamp('processed_at');
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->char('key_hash', 64)->unique(); // sha256
            $table->string('allowed_ips')->nullable(); // через запятую, пусто = любые
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('contract_id')->constrained('storage_contracts');
            $table->decimal('amount', 12, 2);
            $table->date('paid_until')->nullable();
            $table->uuid('onec_doc_guid')->nullable()->unique();
            $table->timestamp('paid_at');
            $table->timestamps();
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations');
            $table->foreignId('row_id')->nullable()->constrained('storage_rows')->nullOnDelete();
            $table->string('status', 16)->default('open'); // open | finished
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->json('result')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained('inventories')->cascadeOnDelete();
            $table->char('cell_address', 8);
            $table->string('qr_code', 40)->nullable(); // null = ячейка пустая
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at');
            $table->index(['inventory_id', 'cell_address']);
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->timestamp('at');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('entity', 64);
            $table->string('entity_id', 40);
            $table->string('action', 16);
            $table->json('diff')->nullable();
            $table->index(['entity', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('inventory_scans');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('integration_inbox');
        Schema::dropIfExists('integration_outbox');
    }
};
