<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3529 (self-serve волна 1, шаг 5): SupportTopicRule становится рантайм-стором
 * правил, синхронизируемых из vendored пакета message-intent-classifier
 * (`php artisan support:rules-sync`).
 *
 * - plane      — плоскость правила пакета (topic|objection|intent|meta); у
 *                легаси-строк остаётся дефолт 'topic'.
 * - pattern_hash — sha256 канонической формы (patterns+negations) — ключ
 *                идемпотентности upsert'а. NULL = легаси-строка, сидированная
 *                вручную; sync её НИКОГДА не трогает.
 * - negations  — блокирующие паттерны правила пакета.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_topic_rules', function (Blueprint $table): void {
            $table->string('plane', 24)->default('topic')->after('category');
            $table->string('pattern_hash', 64)->nullable()->after('category');
            $table->json('negations')->nullable()->after('keywords');

            $table->unique(['plane', 'category', 'pattern_hash'], 'support_topic_rules_sync_unique');
        });
    }

    public function down(): void
    {
        Schema::table('support_topic_rules', function (Blueprint $table): void {
            $table->dropUnique('support_topic_rules_sync_unique');
            $table->dropColumn(['plane', 'pattern_hash', 'negations']);
        });
    }
};
