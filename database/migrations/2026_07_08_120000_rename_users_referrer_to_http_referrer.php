<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Тикет A1 (см. 2026_07_07_120000_add_attribution_fields_to_users): столбец
    // users.referrer (HTTP-реферер регистрации) затенял Eloquent-relation
    // User::referrer() (BelongsTo через referred_by). Из-за этого $user->referrer
    // отдавал URL/NULL вместо пригласившего User — money-core регрессия в
    // ReferralService (награда рефереру не начислялась, точечно погашена в #364).
    // Здесь снимаем мину у корня: переименовываем столбец в http_referrer, чтобы
    // relation referrer() (симметричный referrals()) снова был единственным
    // владельцем имени. Переименование сохраняет данные (столбцу сутки, но на
    // проде уже мог заполниться).
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'referrer') && ! Schema::hasColumn('users', 'http_referrer')) {
                $table->renameColumn('referrer', 'http_referrer');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'http_referrer') && ! Schema::hasColumn('users', 'referrer')) {
                $table->renameColumn('http_referrer', 'referrer');
            }
        });
    }
};
