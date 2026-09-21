<?php

use App\Support\GreetingName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Имя для обращения в уведомлениях («Намасте, {name}!»). Пусто — имя
 * выводится из `name` автоматически ({@see GreetingName}).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'greeting_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('greeting_name', 60)->nullable()->after('name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'greeting_name')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('greeting_name');
            });
        }
    }
};
