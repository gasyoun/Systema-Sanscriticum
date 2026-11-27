<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Плавающий график «N дней можно / M дней нельзя» с якорной датой. */
    public function up(): void
    {
        Schema::table('student_availability_windows', function (Blueprint $table): void {
            $table->string('repeat_mode', 16)->default('weekly')->after('kind')
                ->comment('weekly|cycle');
            $table->unsignedTinyInteger('cycle_on_days')->nullable()->default(2)->after('rrule');
            $table->unsignedTinyInteger('cycle_off_days')->nullable()->default(2)->after('cycle_on_days');
            $table->date('anchor_date')->nullable()->after('cycle_off_days');
        });
    }

    public function down(): void
    {
        Schema::table('student_availability_windows', function (Blueprint $table): void {
            $table->dropColumn(['repeat_mode', 'cycle_on_days', 'cycle_off_days', 'anchor_date']);
        });
    }
};
