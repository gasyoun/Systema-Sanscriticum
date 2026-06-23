<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Рубильники авто-уведомлений студентам в TG/VK. default true — чтобы
    // существующее поведение (рассылки уже работают) не отключилось при деплое.
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('marketing_settings', 'payment_reminders_enabled')) {
                $table->boolean('payment_reminders_enabled')->default(true)->after('student_vk_bot_enabled');
            }
            if (! Schema::hasColumn('marketing_settings', 'class_reminders_enabled')) {
                $table->boolean('class_reminders_enabled')->default(true)->after('payment_reminders_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            foreach (['payment_reminders_enabled', 'class_reminders_enabled'] as $col) {
                if (Schema::hasColumn('marketing_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
