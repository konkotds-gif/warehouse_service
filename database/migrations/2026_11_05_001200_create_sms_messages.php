<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал отправленных SMS со статусом доставки из SMSC (status.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('wheel_set_id')->nullable()->constrained('wheel_sets')->nullOnDelete();
            $table->string('kind', 20); // code — код подтверждения, ready — готов к выдаче, test, other
            $table->string('phone', 32);
            $table->string('driver', 10);
            $table->string('provider_id', 64)->nullable(); // id сообщения в SMSC
            $table->smallInteger('status')->nullable(); // код статуса SMSC (1 — доставлено, 20 — невозможно доставить…)
            $table->smallInteger('status_error')->nullable(); // err из status.php
            $table->timestamp('status_at')->nullable(); // время статуса по данным SMSC
            $table->timestamp('checked_at')->nullable();
            $table->boolean('final')->default(false); // статус окончательный — больше не опрашиваем
            $table->string('error', 255)->nullable(); // ошибка отправки
            $table->timestamps();
            $table->index(['final', 'created_at']);
        });

        Schema::table('contract_sms_codes', function (Blueprint $table) {
            $table->foreignId('sms_message_id')->nullable()->after('provider_id')->constrained('sms_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contract_sms_codes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sms_message_id');
        });
        Schema::dropIfExists('sms_messages');
    }
};
