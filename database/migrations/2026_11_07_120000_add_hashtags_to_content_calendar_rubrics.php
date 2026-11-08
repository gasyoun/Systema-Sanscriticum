<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Хештеги рубрики (07-11-2026): дописываются последней строкой к каждому посту
 * рубрики при публикации в TG и VK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_calendar_rubrics', function (Blueprint $table) {
            $table->string('hashtags', 500)->nullable()->after('prefix');
        });
    }

    public function down(): void
    {
        Schema::table('content_calendar_rubrics', function (Blueprint $table) {
            $table->dropColumn('hashtags');
        });
    }
};
