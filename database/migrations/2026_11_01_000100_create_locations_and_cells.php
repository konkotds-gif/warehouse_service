<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Адресное хранение: локации (магазины и склады), ряды, ячейки XX_YY_ZZ.
 * План, разделы 2 и 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            // склады: '01'..'99' (= XX в адресе); магазины: 'М01'..
            $table->string('code', 4)->unique();
            $table->string('name');
            $table->string('type', 16); // store | warehouse
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('onec_guid')->nullable()->unique();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('location_id')->references('id')->on('locations')->nullOnDelete();
        });

        Schema::create('storage_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->char('row_no', 2); // YY
            $table->string('title')->nullable();
            $table->timestamps();
            $table->unique(['location_id', 'row_no']);
        });

        Schema::create('storage_cells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('row_id')->constrained('storage_rows')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->char('cell_no', 2); // ZZ
            $table->char('address', 8)->unique(); // 'XX_YY_ZZ'
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();
            $table->unique(['row_id', 'cell_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_cells');
        Schema::dropIfExists('storage_rows');
        Schema::table('users', fn (Blueprint $t) => $t->dropForeign(['location_id']));
        Schema::dropIfExists('locations');
    }
};
