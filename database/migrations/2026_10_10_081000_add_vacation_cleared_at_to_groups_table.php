<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H6343: момент снятия флага каникул группы. Второе плечо гибридного гейта
 * анонса возобновления («флаг каникул снят на этой неделе») — обновление
 * группы is_on_vacation true→false ставит метку, по ней и по дате свежести
 * решает VacationResumeService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->timestamp('vacation_cleared_at')->nullable()->after('vacation_resume_date');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            $table->dropColumn('vacation_cleared_at');
        });
    }
};
