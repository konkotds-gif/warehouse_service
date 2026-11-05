<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Склад и магазин в одном здании: точки с одинаковым ключом здания передают колёса без водителя.
 * Перемещение между ними проводится сразу (отгрузка и приёмка одним действием) — признак transfers.same_building.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('building', 32)->nullable()->after('address')->index();
        });
        Schema::table('transfers', function (Blueprint $table) {
            $table->boolean('same_building')->default(false)->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', fn (Blueprint $t) => $t->dropColumn('same_building'));
        Schema::table('locations', function (Blueprint $t) {
            $t->dropIndex(['building']);
            $t->dropColumn('building');
        });
    }
};
