<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_user', function (Blueprint $table) {
            // Номер блока курса, на/после которого студент вышел/был отчислен/
            // выпустился. Для образовательного процесса это понятнее даты:
            // менеджер сразу видит, до какого блока студент дошёл.
            if (! Schema::hasColumn('course_user', 'left_after_block')) {
                $table->unsignedSmallInteger('left_after_block')->nullable()->after('status');
            }

            if (Schema::hasColumn('course_user', 'left_at')) {
                $table->dropColumn('left_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('course_user', function (Blueprint $table) {
            if (! Schema::hasColumn('course_user', 'left_at')) {
                $table->date('left_at')->nullable()->after('status');
            }

            if (Schema::hasColumn('course_user', 'left_after_block')) {
                $table->dropColumn('left_after_block');
            }
        });
    }
};
