<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_discounts', function (Blueprint $table) {
            // NULL = постоянная скидка на ВСЕ блоки курса (дефолт).
            // Номер блока = скидка только на этот блок, перебивает общую.
            if (! Schema::hasColumn('student_discounts', 'block_number')) {
                $table->unsignedSmallInteger('block_number')->nullable()->after('course_id');
                $table->index(['user_id', 'course_id', 'block_number']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('student_discounts', function (Blueprint $table) {
            if (Schema::hasColumn('student_discounts', 'block_number')) {
                $table->dropIndex(['user_id', 'course_id', 'block_number']);
                $table->dropColumn('block_number');
            }
        });
    }
};
