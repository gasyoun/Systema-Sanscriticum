<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            // Разметка материала занятия («emeno», «buhler»...) для material_count-вех.
            // source=auto — проставил сканер стенограмм (LessonMaterialTagger),
            // source=manual — куратор руками; manual сканером не перезаписывается.
            $table->string('material_tag', 50)->nullable();
            $table->string('material_tag_source', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['material_tag', 'material_tag_source']);
        });
    }
};
