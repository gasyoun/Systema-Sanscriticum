<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Привязка промокода к курсу. NULL = код общий (для любого курса).
    // При удалении курса код не удаляется, а становится общим (nullOnDelete).
    public function up(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            if (! Schema::hasColumn('promo_codes', 'course_id')) {
                $table->foreignId('course_id')
                    ->nullable()
                    ->after('value')
                    ->constrained()
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            if (Schema::hasColumn('promo_codes', 'course_id')) {
                $table->dropConstrainedForeignId('course_id');
            }
        });
    }
};
