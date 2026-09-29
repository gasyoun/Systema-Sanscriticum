<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_business_story_publications', function (Blueprint $table) {
            $table->json('story_ids')->nullable();
            $table->unsignedSmallInteger('part_count')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_business_story_publications', function (Blueprint $table) {
            $table->dropColumn(['story_ids', 'part_count']);
        });
    }
};
