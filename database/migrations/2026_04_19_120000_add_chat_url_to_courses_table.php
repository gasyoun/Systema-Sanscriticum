<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            // Ссылка на чат курса (VK / Telegram / Discord — любая URL).
            // Может быть пустой — если null, кнопка в UI не показывается.
            $table->string('chat_url', 500)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('chat_url');
        });
    }
};