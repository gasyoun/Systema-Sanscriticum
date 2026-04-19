<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_views', function (Blueprint $table) {
            $table->id();

            $table->foreignId('article_id')
                  ->constrained('articles')
                  ->cascadeOnDelete(); // удалили статью — ушли её просмотры

            // sha256(ip + user_agent + daily_salt) — 64 hex chars
            $table->char('visitor_hash', 64);

            // Опциональные сырые данные (для аудита и аналитики)
            $table->string('ip', 45)->nullable();      // 45 символов — хватит на IPv6
            $table->string('referrer', 500)->nullable();
            $table->string('user_agent', 500)->nullable();

            // Только created_at, updated_at не нужен
            $table->timestamp('created_at')->useCurrent();

            // Индексы для агрегаций и графиков
            $table->index(['article_id', 'created_at']);
            $table->index(['article_id', 'visitor_hash']); // для подсчёта уников
            $table->index('created_at');                    // для графиков "по всем статьям"
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_views');
    }
};