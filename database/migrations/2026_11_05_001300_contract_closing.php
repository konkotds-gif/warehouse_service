<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Закрытие договора: код из SMS (назначение кода — подписание или закрытие) и реквизиты закрытия в договоре.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contract_sms_codes', function (Blueprint $table) {
            $table->string('purpose', 16)->default('sign')->after('contract_id'); // sign — подписание, close — закрытие
            $table->index(['contract_id', 'purpose']);
        });

        Schema::table('storage_contracts', function (Blueprint $table) {
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            // sms — клиент подтвердил кодом, manual — без кода (руководитель, с причиной), none — SMS не требовалось, auto — закрыт системой
            $table->string('close_confirmation', 16)->nullable()->after('closed_by');
            $table->string('close_phone', 32)->nullable()->after('close_confirmation');
            $table->string('close_note', 255)->nullable()->after('close_phone');
        });
    }

    public function down(): void
    {
        Schema::table('storage_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['close_confirmation', 'close_phone', 'close_note']);
        });
        Schema::table('contract_sms_codes', function (Blueprint $table) {
            $table->dropIndex(['contract_id', 'purpose']);
            $table->dropColumn('purpose');
        });
    }
};
