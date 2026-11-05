<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поштучный скан колёс: бирки на всех колёсах комплекта одинаковые (QR договора),
 * поэтому каждый скан — одно колесо; строка считается погруженной/принятой, когда колёс набралось по договору.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfer_lines', function (Blueprint $table) {
            $table->unsignedTinyInteger('wheels_out')->default(0)->after('qty_in');
            $table->unsignedTinyInteger('wheels_in')->default(0)->after('wheels_out');
        });
    }

    public function down(): void
    {
        Schema::table('transfer_lines', function (Blueprint $table) {
            $table->dropColumn(['wheels_out', 'wheels_in']);
        });
    }
};
