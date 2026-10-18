<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Аудитория @samskrte_bot для карточек открытых занятий: каждый, кто нажал
 * /start (с токеном магнита или без). До этой таблицы бот помнил только
 * лидов (leads.telegram_chat_id) — их и засеваем.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->string('chat_id', 32)->unique();
            $table->string('source', 16)->default('start');
            $table->string('first_name', 128)->nullable();
            $table->timestamp('menu_sent_at')->nullable();
            $table->timestamp('blocked_at')->nullable()->index();
            $table->timestamps();
        });

        $now = now();
        DB::table('leads')
            ->whereNotNull('telegram_chat_id')
            ->distinct()
            ->orderBy('telegram_chat_id')
            ->pluck('telegram_chat_id')
            ->chunk(500)
            ->each(function ($chunk) use ($now): void {
                DB::table('bot_subscribers')->insertOrIgnore($chunk->map(fn ($chatId): array => [
                    'chat_id' => (string) $chatId,
                    'source' => 'lead',
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_subscribers');
    }
};
