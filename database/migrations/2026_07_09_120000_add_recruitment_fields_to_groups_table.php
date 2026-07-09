<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // H162: набор группы — статус + плановая/фактическая дата старта +
    // дедуп уведомлений об угрозе недобора (groups:notify-forming-shortfall).
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            if (! Schema::hasColumn('groups', 'status')) {
                $table->string('status')->default('forming')->after('intake_id');
            }
            if (! Schema::hasColumn('groups', 'min_size')) {
                $table->unsignedTinyInteger('min_size')->nullable()->after('status');
            }
            if (! Schema::hasColumn('groups', 'planned_start_date')) {
                $table->date('planned_start_date')->nullable()->after('min_size');
            }
            if (! Schema::hasColumn('groups', 'start_date_override')) {
                $table->date('start_date_override')->nullable()->after('planned_start_date');
            }
            if (! Schema::hasColumn('groups', 'recruitment_notified_at')) {
                $table->timestamp('recruitment_notified_at')->nullable()->after('start_date_override');
            }
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            foreach (['status', 'min_size', 'planned_start_date', 'start_date_override', 'recruitment_notified_at'] as $col) {
                if (Schema::hasColumn('groups', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
