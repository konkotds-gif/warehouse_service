<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Доверенное лицо «ко всем машинам владельца»: all_vehicles = 1 даёт доступ ко всем машинам owner_id,
 * в том числе к появившимся позже. vehicle_id остаётся машиной, при которой доверенность добавили.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_persons', function (Blueprint $table) {
            $table->foreignUuid('owner_id')->nullable()->after('client_id')->constrained('clients')->cascadeOnDelete();
            $table->boolean('all_vehicles')->default(false)->after('owner_id');
            $table->index(['owner_id', 'all_vehicles', 'revoked_at']);
        });
        DB::table('vehicle_persons')->update([
            'owner_id' => DB::raw('(SELECT client_id FROM vehicles WHERE vehicles.id = vehicle_persons.vehicle_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('vehicle_persons', function (Blueprint $table) {
            $table->dropIndex(['owner_id', 'all_vehicles', 'revoked_at']);
            $table->dropConstrainedForeignId('owner_id');
            $table->dropColumn('all_vehicles');
        });
    }
};
