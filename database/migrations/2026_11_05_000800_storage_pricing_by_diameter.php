<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Стоимость хранения по прайсу 1С: цена за 1 колесо в месяц по диаметру.
 * Стоимость комплекта = цена(диаметр) × колёса × месяцы. Коды номенклатуры 1С (ЦБ…) — в tariffs.code.
 */
return new class extends Migration
{
    /** Прайс-лист «Хранение как услуги» на 05.11.2026. [код 1С, наименование, диаметр от, до, цена] */
    public const PRICE_LIST = [
        ['ЦБ009508', 'R-13 - сезонное хранение шины', 13, 13, 100],
        ['ЦБ000333', 'R-14 - сезонное хранение шины', 14, 14, 100],
        ['ЦБ000334', 'R-15 - сезонное хранение шины', 15, 15, 110],
        ['ЦБ000335', 'R-16 - сезонное хранение шины', 16, 16, 120],
        ['ЦБ000336', 'R-17 - сезонное хранение шины', 17, 17, 130],
        ['ЦБ000337', 'R-18 - сезонное хранение шины', 18, 18, 150],
        ['ЦБ000338', 'R-19 - сезонное хранение шины', 19, 19, 170],
        ['ЦБ000564', 'R-20 - сезонное хранение шины', 20, 20, 180],
        ['ЦБ004139', 'R-21 22 - сезонное хранение шины', 21, 22, 200],
    ];

    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->unsignedTinyInteger('diameter_from')->nullable()->after('name');
            $table->unsignedTinyInteger('diameter_to')->nullable()->after('diameter_from');
        });
        Schema::table('storage_contracts', function (Blueprint $table) {
            $table->unsignedSmallInteger('months')->nullable()->after('storage_until');
            // Плата за хранение после бесплатных дней, уже начисленная при выдаче (накопительно по договору)
            $table->decimal('overdue_fee', 12, 2)->default(0)->after('amount');
            $table->unsignedInteger('overdue_days')->default(0)->after('overdue_fee');
        });
        Schema::table('wheel_sets', function (Blueprint $table) {
            $table->foreignId('tariff_id')->nullable()->after('contract_id')->constrained('tariffs')->nullOnDelete();
            $table->decimal('price', 12, 2)->nullable()->after('tariff_id'); // за 1 колесо в месяц на момент приёма
            $table->decimal('amount', 12, 2)->default(0)->after('price');
        });

        $now = now();
        foreach (self::PRICE_LIST as [$code, $name, $from, $to, $price]) {
            $values = ['name' => $name, 'diameter_from' => $from, 'diameter_to' => $to, 'price' => $price,
                'period' => 'month', 'months' => 1, 'is_active' => true, 'updated_at' => $now];
            if (DB::table('tariffs')->where('code', $code)->exists()) {
                DB::table('tariffs')->where('code', $code)->update($values);
            } else {
                DB::table('tariffs')->insert($values + ['code' => $code, 'created_at' => $now]);
            }
        }
        // Старые тарифы «за комплект» больше не используются при приёме
        DB::table('tariffs')->whereNull('diameter_from')->update(['is_active' => false]);
    }

    public function down(): void
    {
        Schema::table('wheel_sets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tariff_id');
            $table->dropColumn(['price', 'amount']);
        });
        Schema::table('storage_contracts', function (Blueprint $table) {
            $table->dropColumn(['months', 'overdue_fee', 'overdue_days']);
        });
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn(['diameter_from', 'diameter_to']);
        });
    }
};
