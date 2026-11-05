<?php

use Database\Seeders\TireBrandSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Справочник брендов шин. Начальный список заливается сразу (deploy.sh делает только migrate). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tire_brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        (new TireBrandSeeder)->run();
    }

    public function down(): void
    {
        Schema::dropIfExists('tire_brands');
    }
};
