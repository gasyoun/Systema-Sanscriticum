<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Маркер «приветственное письмо с сгенерированным паролем уже отправлено».
 * Гарантирует, что пароль студенту генерируется РОВНО ОДИН РАЗ на аккаунт —
 * иначе повторный вход платежа в paid-статус (round-trip canceled→paid,
 * ре-сейв legacy 'success', поздний вебхук) при единственной оплате снова
 * перегенерировал бы пароль и ломал вход студенту (money-core H071 #15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('welcome_email_sent_at')->nullable()->after('cabinet_invite_sent_at');
        });

        // Бэкфилл: у существующих студентов с уже прошедшей оплатой приветствие
        // де-факто отправлено — помечаем, чтобы round-trip старого платежа не
        // перегенерировал их пароль. Метка нужна только как «не первый раз».
        DB::table('users')
            ->whereIn('id', function ($q) {
                $q->select('user_id')
                    ->from('payments')
                    ->whereIn('status', ['paid', 'success'])
                    ->whereNotNull('user_id');
            })
            ->update(['welcome_email_sent_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('welcome_email_sent_at');
        });
    }
};
