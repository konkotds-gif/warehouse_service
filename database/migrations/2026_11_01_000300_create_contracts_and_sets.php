<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Договоры хранения, комплекты (экземпляры хранения), бирки, фото,
 * перемещения, задания и неизменяемый журнал движений.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->string('name', 32)->primary();
            $table->unsignedBigInteger('value')->default(0);
        });

        Schema::create('storage_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 32)->unique(); // зашит в QR бирок
            $table->foreignUuid('client_id')->constrained('clients');
            $table->foreignUuid('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('store_id')->constrained('locations'); // магазин приёма
            $table->foreignId('tariff_id')->nullable()->constrained('tariffs')->nullOnDelete();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at');
            $table->date('paid_until')->nullable(); // оплачено до
            $table->date('storage_until')->nullable(); // срок хранения по договору
            $table->decimal('amount', 12, 2)->default(0);
            $table->decimal('debt', 12, 2)->default(0); // долг по данным 1С
            $table->timestamp('debt_updated_at')->nullable();
            $table->string('status', 16)->default('open'); // open | overdue | closed
            $table->uuid('onec_doc_guid')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
        });

        Schema::create('transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 32)->unique();
            $table->foreignId('from_location_id')->constrained('locations');
            $table->foreignId('to_location_id')->constrained('locations');
            $table->string('status', 16)->default('created');
            $table->string('purpose', 16)->default('storage'); // storage | issue | relocation
            $table->string('driver')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::create('wheel_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('garage_set_id')->nullable()->constrained('garage_sets')->nullOnDelete();
            $table->foreignUuid('contract_id')->constrained('storage_contracts');
            $table->string('qr_code', 40)->unique(); // '000123' или '000123/2'; не меняется никогда
            $table->unsignedTinyInteger('qty');
            $table->string('tire_size', 40);
            $table->boolean('with_rims');
            $table->string('condition', 8);
            $table->string('season', 16)->nullable();
            $table->string('brand_model')->nullable();
            $table->string('status', 16);
            $table->foreignId('current_cell_id')->nullable()->constrained('storage_cells')->restrictOnDelete();
            $table->foreignId('current_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignUuid('transfer_id')->nullable()->constrained('transfers')->nullOnDelete();
            $table->foreignId('issue_store_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->date('issue_due_date')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        if ($this->isMysqlLike()) {
            DB::statement("ALTER TABLE wheel_sets ADD CONSTRAINT chk_placed_has_cell CHECK (status <> 'placed' OR current_cell_id IS NOT NULL)");
            DB::statement('ALTER TABLE wheel_sets ADD CONSTRAINT chk_qty CHECK (qty BETWEEN 1 AND 8)');
            DB::statement("ALTER TABLE wheel_sets ADD CONSTRAINT chk_condition CHECK (`condition` IN ('used','new'))");
        }

        Schema::create('transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('transfer_id')->constrained('transfers')->cascadeOnDelete();
            $table->foreignUuid('wheel_set_id')->constrained('wheel_sets');
            $table->boolean('scanned_out')->default(false);
            $table->boolean('scanned_in')->default(false);
            $table->unsignedTinyInteger('qty_out')->nullable();
            $table->unsignedTinyInteger('qty_in')->nullable();
            $table->timestamps();
            $table->unique(['transfer_id', 'wheel_set_id']);
        });

        Schema::create('tag_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('wheel_set_id')->constrained('wheel_sets')->cascadeOnDelete();
            $table->unsignedTinyInteger('copies');
            $table->string('reason', 16); // accept | reprint
            $table->string('format', 8)->default('html'); // html | zpl
            $table->foreignId('printed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('printed_at');
        });

        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('wheel_set_id')->constrained('wheel_sets')->cascadeOnDelete();
            $table->string('path');
            $table->foreignId('taken_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('taken_at');
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('type', 24); // place | pick | prepare_issue | inventory | discrepancy
            $table->foreignUuid('wheel_set_id')->nullable()->constrained('wheel_sets')->nullOnDelete();
            $table->foreignId('location_id')->constrained('locations');
            $table->date('due_date')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('open'); // open | done | cancelled
            $table->string('title');
            $table->json('payload')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'status']);
        });

        // Неизменяемый журнал — главный источник правды по истории
        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('wheel_set_id')->constrained('wheel_sets');
            $table->timestamp('at', 3);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 24);
            $table->string('from_address', 40)->nullable();
            $table->string('to_address', 40)->nullable();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->uuid('transfer_id')->nullable();
            $table->unsignedTinyInteger('qty')->nullable();
            $table->string('device', 120)->nullable();
            $table->string('note')->nullable();
            $table->index(['wheel_set_id', 'id']);
        });

        $this->createImmutableTriggers();
    }

    private function isMysqlLike(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function createImmutableTriggers(): void
    {
        $message = 'movements is append-only';

        if ($this->isMysqlLike()) {
            foreach (['UPDATE', 'DELETE'] as $op) {
                $name = 'movements_no_'.strtolower($op);
                DB::unprepared("DROP TRIGGER IF EXISTS {$name}");
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$op} ON movements FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'");
            }
        } elseif (DB::getDriverName() === 'sqlite') {
            foreach (['UPDATE', 'DELETE'] as $op) {
                $name = 'movements_no_'.strtolower($op);
                DB::unprepared("CREATE TRIGGER IF NOT EXISTS {$name} BEFORE {$op} ON movements BEGIN SELECT RAISE(ABORT, '{$message}'); END");
            }
        }
    }

    public function down(): void
    {
        if ($this->isMysqlLike() || DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS movements_no_update');
            DB::unprepared('DROP TRIGGER IF EXISTS movements_no_delete');
        }
        Schema::dropIfExists('movements');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('photos');
        Schema::dropIfExists('tag_prints');
        Schema::dropIfExists('transfer_lines');
        Schema::dropIfExists('wheel_sets');
        Schema::dropIfExists('transfers');
        Schema::dropIfExists('storage_contracts');
        Schema::dropIfExists('number_sequences');
    }
};
