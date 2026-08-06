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
        });

        // MySQL 8 error 1553: unique (user_id, course_id, milestone) is the
        // supporting index for the user_id FK (leftmost prefix). Drop the FKs
        // that may rely on it, swap the unique, then restore the FKs.
        $this->dropCertificateFks();

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropUnique('certificates_user_course_milestone_unique');
            $table->unique(
                ['user_id', 'course_id', 'certificate_milestone_id', 'occurrence'],
                'certificates_user_course_milestone_occ_unique'
            );
        });

        $this->restoreCertificateFks();
    }

    public function down(): void
    {
        $this->dropCertificateFks();

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropUnique('certificates_user_course_milestone_occ_unique');
            $table->unique(
                ['user_id', 'course_id', 'certificate_milestone_id'],
                'certificates_user_course_milestone_unique'
            );
        });

        $this->restoreCertificateFks();

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('group_id');
            $table->dropColumn(['occurrence', 'document_type']);
        });
    }

    private function dropCertificateFks(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['course_id']);
            $table->dropForeign(['certificate_milestone_id']);
        });
    }

    private function restoreCertificateFks(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('course_id')->references('id')->on('courses')->cascadeOnDelete();
            $table->foreign('certificate_milestone_id')
                ->references('id')
                ->on('certificate_milestones')
                ->nullOnDelete();
        });
    }
};
