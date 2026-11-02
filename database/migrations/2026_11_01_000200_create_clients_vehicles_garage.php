<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Клиенты, автомобили, тарифы (услуги 1С) и гараж клиента.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('onec_guid')->nullable()->unique();
            $table->string('full_name');
            $table->string('phone', 16)->nullable()->index(); // +7XXXXXXXXXX
            $table->string('email')->nullable();
            $table->boolean('consent_sms')->default(false);
            $table->boolean('consent_pd')->default(false); // согласие на обработку ПДн (152-ФЗ)
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('plate', 16)->nullable()->index(); // нормализованный госномер
            $table->string('make', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            $table->uuid('onec_guid')->nullable()->unique();
            $table->string('code', 32)->unique();
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->string('period', 16)->default('season'); // season | month
            $table->unsignedSmallInteger('months')->default(6); // срок по умолчанию
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('garage_sets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('title');
            $table->string('season', 16)->nullable();
            $table->unsignedTinyInteger('qty');
            $table->string('tire_size', 40);
            $table->boolean('with_rims');
            $table->string('condition', 8); // used | new
            $table->string('brand_model')->nullable();
            $table->string('notes')->nullable();
            $table->string('state', 16)->default('with_client');
            $table->uuid('last_contract_id')->nullable();
            $table->timestamp('last_issued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garage_sets');
        Schema::dropIfExists('tariffs');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('clients');
    }
};
