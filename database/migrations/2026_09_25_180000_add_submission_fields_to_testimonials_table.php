<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отзывы, которые студент пишет сам в кабинете (/dvaram/otzyv), и их модерация:
 * - user_id — автор-студент; у отзывов, внесённых админом, пусто;
 * - moderation_status — pending | approved | rejected. По умолчанию approved,
 *   чтобы уже опубликованные отзывы остались в пуле как есть;
 * - publish_consent_at — когда студент поставил галочку согласия на публикацию
 *   (таблица отзывов сама по себе согласием не является — cabinet_mastery d18);
 * - submitted_at — когда отзыв отправлен из кабинета.
 * Видимость по-прежнему решает is_visible: до одобрения он false.
 * Аддитивно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained()->nullOnDelete();
            $table->string('moderation_status', 16)->default('approved')->after('show_on_login')->index();
            $table->timestamp('publish_consent_at')->nullable()->after('moderation_status');
            $table->timestamp('submitted_at')->nullable()->after('publish_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
            $table->dropIndex(['moderation_status']);
            $table->dropColumn(['moderation_status', 'publish_consent_at', 'submitted_at']);
        });
    }
};
