<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // Группа, по которой сработал пер-групповой триггер (реестру нужна
            // разбивка «погрупно»). NULL — ручная выдача или курс-уровневая веха.
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            // Итерация повторяющейся вехи («каждые 10 занятий» → 1, 2, 3...).
            $table->unsignedSmallInteger('occurrence')->default(1);
            // Снапшот типа документа с вехи: certificate | spravka.
            $table->string('document_type', 20)->default('certificate');

            // Повторяющиеся вехи требуют occurrence в ключе идемпотентности.
            $table->dropUnique('certificates_user_course_milestone_unique');
            $table->unique(
                ['user_id', 'course_id', 'certificate_milestone_id', 'occurrence'],
                'certificates_user_course_milestone_occ_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropUnique('certificates_user_course_milestone_occ_unique');
            $table->unique(
                ['user_id', 'course_id', 'certificate_milestone_id'],
                'certificates_user_course_milestone_unique'
            );
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn(['occurrence', 'document_type']);
        });
    }
};
