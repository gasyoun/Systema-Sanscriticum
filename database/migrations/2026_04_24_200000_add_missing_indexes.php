<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('course_id');
            $table->index(['course_id', 'status']);
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->index('teacher_id');
        });

        Schema::table('tariffs', function (Blueprint $table) {
            $table->index('course_id');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->index('user_id');
            $table->index('is_read');
        });

        Schema::table('imports', function (Blueprint $table) {
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['course_id']);
            $table->dropIndex(['course_id', 'status']);
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->dropIndex(['teacher_id']);
        });

        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropIndex(['course_id']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['is_read']);
        });

        Schema::table('imports', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });
    }
};
