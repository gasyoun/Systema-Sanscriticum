<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0 авто-постинга ссылки Zoom в чат группы перед занятием.
 * - groups.telegram_chat_id — единственный недостающий маппинг «группа → TG-чат».
 * - schedules.group_link_posted_at — дедуп, чтобы ссылка постилась один раз на событие.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            // chat_id Telegram-чата группы (может быть отрицательным для супергрупп —
            // поэтому строка, а не bigint; храним как есть из Bot API).
            $table->string('telegram_chat_id')->nullable()->after('slug');
        });

        Schema::table('schedules', function (Blueprint $table): void {
            $table->timestamp('group_link_posted_at')->nullable()->after('reminded_at');
        });

        Schema::table('marketing_settings', function (Blueprint $table): void {
            // Рубильник и окно (минуты до старта) авто-постинга ссылки в чат группы.
            $table->boolean('class_link_autopost_enabled')->default(false);
            $table->unsignedInteger('class_link_autopost_lead_minutes')->default(15);
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropColumn('telegram_chat_id');
        });

        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropColumn('group_link_posted_at');
        });

        Schema::table('marketing_settings', function (Blueprint $table): void {
            $table->dropColumn(['class_link_autopost_enabled', 'class_link_autopost_lead_minutes']);
        });
    }
};
