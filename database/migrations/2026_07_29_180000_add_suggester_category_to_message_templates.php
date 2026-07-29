<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Привязка «категория суггестера → шаблон» (H1838, тикет S9): шаблон с
     * заполненной suggester_category (D/E/F) питает черновики FAQ-суггестера
     * вместо LLM-вызова. NULL — шаблон в суггестере не участвует (как раньше).
     */
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('suggester_category', 8)->nullable()->index()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('suggester_category');
        });
    }
};
