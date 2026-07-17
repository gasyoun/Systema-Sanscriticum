<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1198 (Jivo-паритет S3): FAQ-суггестер (H247) распространяется на гостевые
 * веб-треды (аноним без `users`-записи) — черновик для категории D (публичные
 * тарифы, без персонализации). `user_id` = NULL помечает такую догадку;
 * привязка к треду идёт через `source_id` (ChatMessage.support_conversation_id),
 * без новой колонки. Для изменения колонки нужен doctrine/dbal — в Laravel 11+
 * уже встроен.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_answer_suggestions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('support_answer_suggestions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
