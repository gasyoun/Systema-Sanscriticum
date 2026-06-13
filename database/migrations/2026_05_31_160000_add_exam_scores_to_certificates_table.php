<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // Баллы за экзамен по трём дисциплинам (дробные: 18, 4.5, 19.5...).
            // Итог считается как сумма, отдельно не хранится.
            $table->decimal('score_clarity', 4, 1)->nullable()->after('template'); // выразительность /20
            $table->decimal('score_letters', 4, 1)->nullable()->after('score_clarity');    // дикция /5
            $table->decimal('score_flow', 4, 1)->nullable()->after('score_letters');        // плавность /5
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['score_clarity', 'score_letters', 'score_flow']);
        });
    }
};
