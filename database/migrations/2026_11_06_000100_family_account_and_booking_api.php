<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Семейный аккаунт (вариант Г: машина — главное звено) и API для онлайн-записи.
 * - vehicle_persons: доверенные лица при машине (владелец — vehicles.client_id);
 * - api_keys.scopes: область ключа (onec — 1С, booking — онлайн-запись);
 * - booking_orders: заказы к выдаче, созданные записью на услугу;
 * - api_access_log: журнал поиска хранений по телефону/госномеру (152-ФЗ).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_persons', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('source', 16)->default('staff'); // staff | booking
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 32)->nullable(); // owner | staff | self | owner_changed | merged
            $table->timestamps();
            $table->index(['vehicle_id', 'revoked_at']);
            $table->index(['client_id', 'revoked_at']);
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->json('scopes')->nullable()->after('allowed_ips');
        });
        DB::table('api_keys')->update(['scopes' => json_encode(['onec'])]);

        Schema::create('booking_orders', function (Blueprint $table) {
            $table->id();
            $table->string('booking_code', 40)->unique();
            $table->foreignUuid('wheel_set_id')->constrained('wheel_sets')->cascadeOnDelete();
            $table->foreignId('store_id')->constrained('locations');
            $table->dateTime('starts_at');
            $table->string('phone', 16);
            $table->string('status', 16)->default('active'); // active | cancelled | issued
            $table->timestamps();
            $table->index(['wheel_set_id', 'status']);
        });

        Schema::create('api_access_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->nullable()->constrained('api_keys')->nullOnDelete();
            $table->string('action', 32);
            $table->string('phone', 16)->nullable();
            $table->string('plate', 16)->nullable();
            $table->string('ip', 45)->nullable();
            $table->unsignedSmallInteger('found')->default(0);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_access_log');
        Schema::dropIfExists('booking_orders');
        Schema::table('api_keys', fn (Blueprint $table) => $table->dropColumn('scopes'));
        Schema::dropIfExists('vehicle_persons');
    }
};
