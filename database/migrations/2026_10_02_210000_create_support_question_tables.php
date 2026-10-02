<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H5709 — недельная аналитика студенческих вопросов Telegram.
 *
 * Три таблицы:
 *  - support_question_classifications — версионируемые ПОСООБЩЕННЫЕ метки
 *    (классификатор уровня сообщения; дневной SupportTopicClassifier
 *    конкатенирует тексты за день и для подсчёта вопросов не годится);
 *  - support_question_weekly_snapshots — агрегаты по неделе (Monday-start),
 *    уникальность (week_start, classifier_version) даёт сравнимость версий;
 *  - support_question_weekly_deliveries — леджер доставки exactly-once:
 *    клейм ДО сетевого вызова, состояния до/после, никаких слепых ретраев.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_question_classifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_support_message_id')->constrained()->cascadeOnDelete();
            $table->string('classifier_version', 32);
            $table->string('population', 24); // student|enquiry|staff_internal|unknown
            $table->boolean('is_question');
            $table->string('primary_category', 32)->nullable(); // A..I | null
            $table->json('secondary_categories')->nullable();
            $table->string('exclusion_reason', 32)->nullable(); // service_message|bot|duplicate_import|not_question
            $table->json('flags')->nullable(); // сигналы детекции + попадания правил (без текста)
            $table->timestamps();

            $table->unique(
                ['telegram_support_message_id', 'classifier_version'],
                'support_question_classification_unique'
            );
            // Имя задано явно: авто-имя этого композита длиннее 64 символов
            // и ломает MySQL 1059 (SQLite молчит — ловится только CI-джобой).
            $table->index(
                ['classifier_version', 'population', 'is_question'],
                'sqc_version_population_question_idx'
            );
        });

        Schema::create('support_question_weekly_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('week_start'); // Monday
            $table->string('classifier_version', 32);
            $table->json('payload');
            $table->boolean('is_incomplete')->default(false);
            $table->string('incompleteness_reason', 64)->nullable();
            $table->timestamps();

            $table->unique(
                ['week_start', 'classifier_version'],
                'support_question_weekly_snapshot_unique'
            );
            $table->index(['week_start', 'is_incomplete']);
        });

        Schema::create('support_question_weekly_deliveries', function (Blueprint $table) {
            $table->id();
            $table->date('week_start')->unique();
            // pending|claimed|unknown|acknowledged|not_delivered
            $table->string('state', 16)->default('pending');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->string('suppress_reason', 48)->nullable(); // already_acknowledged|dedup_guard|...
            $table->timestamp('reconciled_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_question_weekly_deliveries');
        Schema::dropIfExists('support_question_weekly_snapshots');
        Schema::dropIfExists('support_question_classifications');
    }
};
