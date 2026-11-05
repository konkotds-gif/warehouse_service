<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Вход через доменную учётную запись (Active Directory):
 * источник учётки у пользователя и сопоставление «группа AD → роль платформы».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_source', 16)->default('local')->after('password'); // local | ldap
            $table->string('ldap_guid', 64)->nullable()->unique()->after('auth_source'); // objectGUID — не меняется при переименовании
            $table->string('ldap_dn', 500)->nullable()->after('ldap_guid');
            $table->timestamp('ldap_synced_at')->nullable()->after('ldap_dn');
            // Доступ закрыт со стороны AD (учётка отключена или убрана из групп) — отдельно от ручной блокировки is_active
            $table->timestamp('ldap_denied_at')->nullable()->after('ldap_synced_at');
        });

        Schema::create('ldap_group_roles', function (Blueprint $table) {
            $table->id();
            $table->string('group_dn', 500);
            $table->string('group_name', 255);
            $table->string('role', 32);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_group_roles');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['ldap_guid']);
            $table->dropColumn(['auth_source', 'ldap_guid', 'ldap_dn', 'ldap_synced_at', 'ldap_denied_at']);
        });
    }
};
