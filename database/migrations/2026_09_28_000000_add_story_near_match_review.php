<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_business_story_publications', function (Blueprint $table): void {
            $table->json('video_fingerprint')->nullable();
            $table->json('source_post')->nullable();
            $table->timestamp('near_match_approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_business_story_publications', function (Blueprint $table): void {
            $table->dropColumn(['video_fingerprint', 'source_post', 'near_match_approved_at']);
        });
    }
};
