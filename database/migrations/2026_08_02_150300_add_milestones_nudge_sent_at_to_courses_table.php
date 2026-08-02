<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Одновременно флаг «курс без вех, требует внимания» (badge в админке)
            // и дедуп уведомления кураторам. Сбрасывается created-хуком
            // CertificateMilestone, чтобы детектор мог сработать повторно.
            $table->timestamp('milestones_nudge_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('milestones_nudge_sent_at');
        });
    }
};
