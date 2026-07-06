<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Реальная группа (Группа 61) порождается набором (Intake). Nullable —
        // исторические группы созданы до появления наборов и остаются без привязки.
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('intake_id')->nullable()->after('slug')
                ->constrained('intakes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('intake_id');
        });
    }
};
