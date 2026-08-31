<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Каникулы групп (H3790): явный флаг «на каникулах» + дата выхода.
        // Пустая дата при включённом флаге = «дата уточняется» — триггерит опрос
        // кворума в последнюю неделю августа (команда schedule:vacation-quorum).
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('is_on_vacation')->default(false)->after('recruitment_notified_at');
            $table->date('vacation_resume_date')->nullable()->after('is_on_vacation');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn(['is_on_vacation', 'vacation_resume_date']);
        });
    }
};
