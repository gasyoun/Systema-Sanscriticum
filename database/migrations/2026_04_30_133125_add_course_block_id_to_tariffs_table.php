<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->foreignId('course_block_id')
                ->nullable()
                ->after('block_number')
                ->constrained('course_blocks')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('course_block_id');
        });
    }
};
