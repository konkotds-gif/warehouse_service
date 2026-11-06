<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Заказ под запись — все комплекты договора того же сезона: разноширокие оси хранятся двумя комплектами
 * (245/45 R19 и 275/45 R19), а клиент выбирает в записи один. extra_set_ids — заказанные вместе с основным.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_orders', function (Blueprint $table) {
            $table->json('extra_set_ids')->nullable()->after('wheel_set_id');
        });
    }

    public function down(): void
    {
        Schema::table('booking_orders', fn (Blueprint $t) => $t->dropColumn('extra_set_ids'));
    }
};
