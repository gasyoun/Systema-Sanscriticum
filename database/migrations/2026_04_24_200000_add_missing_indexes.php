<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Дополнительные индексы поверх неявных FK-индексов (foreignId()->constrained()
        // на MySQL/InnoDB автоматически создаёт индекс на ссылочной колонке).
        // Здесь только то, что реально добавляет ценность для запросов.
        Schema::table('payments', function (Blueprint $table) {
            $table->index(['course_id', 'status']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->index('is_read');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['course_id', 'status']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['is_read']);
        });
    }
};
