<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ведомость заданий на перевозку (рейс): водитель забирает одно или несколько перемещений,
 * погрузка подтверждается сканом бирок сразу по всей ведомости.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number', 32)->unique();
            $table->string('status', 16)->default('loading'); // loading | shipped | done | cancelled
            $table->string('driver')->nullable();
            $table->string('vehicle')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index('status');
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->foreignUuid('trip_id')->nullable()->after('driver')->constrained('trips')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trip_id');
        });
        Schema::dropIfExists('trips');
    }
};
