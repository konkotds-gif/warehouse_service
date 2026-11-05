<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник марок и моделей авто (загружается по API системы записи, команда cars:sync),
 * таблица настроек платформы и ссылки автомобиля клиента на справочник.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('car_makes', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary(); // id из справочника системы записи
            $table->string('slug', 64)->unique();
            $table->string('name', 100)->index();
            $table->json('aliases')->nullable();
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('car_models', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('car_make_id');
            $table->string('slug', 100);
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['car_make_id', 'name']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->unsignedBigInteger('car_make_id')->nullable()->after('model')->index();
            $table->unsignedBigInteger('car_model_id')->nullable()->after('car_make_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['car_make_id']);
            $table->dropIndex(['car_model_id']);
            $table->dropColumn(['car_make_id', 'car_model_id']);
        });
        Schema::dropIfExists('car_models');
        Schema::dropIfExists('car_makes');
        Schema::dropIfExists('settings');
    }
};
