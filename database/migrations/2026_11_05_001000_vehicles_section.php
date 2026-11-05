<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Раздел «Автомобили»: дополнительные реквизиты авто (VIN, год, цвет, примечание)
 * и история смены владельца (авто продали вместе с колёсами или без).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('vin', 20)->nullable()->after('plate')->index(); // VIN или номер кузова/рамы
            $table->unsignedSmallInteger('year')->nullable()->after('car_model_id');
            $table->string('color', 32)->nullable()->after('year');
            $table->string('notes')->nullable()->after('color');
        });

        Schema::create('vehicle_owner_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->uuid('from_client_id')->nullable()->index();
            $table->uuid('to_client_id')->index();
            $table->boolean('sets_moved')->default(true); // комплекты гаража ушли вместе с авто
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_owner_changes');
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['vin']);
            $table->dropColumn(['vin', 'year', 'color', 'notes']);
        });
    }
};
