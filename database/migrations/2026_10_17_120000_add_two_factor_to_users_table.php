<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H6352-r5 (рулинг 7 грилла 10-10): 2FA для всех админ-ролей.
 *
 * Секрет TOTP и резервные коды хранятся в зашифрованных кастах users
 * (см. App\Models\User::$casts), поэтому колонки — TEXT: base64 шифротекста
 * длиннее 255 символов. Подтверждение — отдельной меткой времени, чтобы
 * «начал, но не довёл вход» не считался включённой защитой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable()->after('password');
            $table->text('two_factor_recovery_codes')->nullable()->after('two_factor_secret');
            $table->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
