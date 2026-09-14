<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H-новизна (MG ruling 31-08-2026): витринная категория новизны курса для
 * «только новые курсы» в анонсах.
 *   new      — программа вообще впервые;
 *   repeat   — возвращается после года-двух;
 *   no_repeat— повтора точно не будет (для анонсов не годится);
 *   usual    — обычный регулярный курс (default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('novelty')->default('usual')->after('never_repeat');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('novelty');
        });
    }
};
