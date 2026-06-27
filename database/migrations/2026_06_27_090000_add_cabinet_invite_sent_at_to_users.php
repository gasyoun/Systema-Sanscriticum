<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Когда студенту повторно отправили приглашение войти в кабинет
            // (рассылка спящим оплатившим). NULL = ещё не приглашали.
            $table->timestamp('cabinet_invite_sent_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('cabinet_invite_sent_at');
        });
    }
};
