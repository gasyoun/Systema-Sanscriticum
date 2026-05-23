<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            // Главная страница фильтрует show_on_main + is_free + is_published —
            // без индекса на флаге запрос пойдёт full scan при росте таблицы.
            $table->boolean('show_on_main')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table): void {
            $table->dropIndex(['show_on_main']);
            $table->dropColumn('show_on_main');
        });
    }
};
