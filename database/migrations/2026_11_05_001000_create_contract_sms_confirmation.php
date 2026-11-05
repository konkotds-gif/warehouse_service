<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подтверждение договора кодом из SMS (SMSC): отправленные коды и отметка о подтверждении в договоре.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_sms_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('contract_id')->constrained('storage_contracts');
            $table->string('phone', 32);
            $table->string('code_hash', 64); // HMAC-SHA256 кода, сам код не хранится
            $table->timestamp('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('provider_id', 64)->nullable(); // id сообщения в SMSC
            $table->string('error', 255)->nullable(); // текст ошибки шлюза, если не отправлено
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['contract_id', 'created_at']);
        });

        Schema::table('storage_contracts', function (Blueprint $table) {
            $table->timestamp('sms_confirmed_at')->nullable()->after('notes');
            $table->string('sms_confirmed_phone', 32)->nullable()->after('sms_confirmed_at');
            $table->foreignId('sms_confirmed_by')->nullable()->after('sms_confirmed_phone')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('storage_contracts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sms_confirmed_by');
            $table->dropColumn(['sms_confirmed_at', 'sms_confirmed_phone']);
        });
        Schema::dropIfExists('contract_sms_codes');
    }
};
