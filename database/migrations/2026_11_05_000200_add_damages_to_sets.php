<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Повреждения БУ-комплекта (коды App\Domain\Storage\Damage) — в комплекте на хранении и в гараже клиента. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wheel_sets', function (Blueprint $table) {
            $table->json('damages')->nullable()->after('brand_model');
        });
        Schema::table('garage_sets', function (Blueprint $table) {
            $table->json('damages')->nullable()->after('brand_model');
        });
    }

    public function down(): void
    {
        Schema::table('wheel_sets', fn (Blueprint $table) => $table->dropColumn('damages'));
        Schema::table('garage_sets', fn (Blueprint $table) => $table->dropColumn('damages'));
    }
};
