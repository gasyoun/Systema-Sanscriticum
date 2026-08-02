<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // nullOnDelete: сертификат — исторический документ, при удалении
            // вехи он остаётся (со снапшотами имени/названия/шаблона).
            $table->foreignId('certificate_milestone_id')
                ->nullable()
                ->constrained('certificate_milestones')
                ->nullOnDelete();
            // Дедуп уведомления о выдаче (паттерн recruitment_notified_at).
            $table->timestamp('notified_at')->nullable();

            // NULL-ы не конфликтуют между собой → ручные сертификаты
            // (milestone NULL) не ограничены, автовыдача защищена от гонок.
            $table->unique(
                ['user_id', 'course_id', 'certificate_milestone_id'],
                'certificates_user_course_milestone_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropUnique('certificates_user_course_milestone_unique');
            $table->dropConstrainedForeignId('certificate_milestone_id');
            $table->dropColumn('notified_at');
        });
    }
};
