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
            $table->string('source_media_path')->nullable();
            $table->longText('subtitle_draft')->nullable();
            $table->string('subtitle_status')->nullable();
            $table->string('subtitle_worker')->nullable();
            $table->timestamp('subtitle_requested_at')->nullable();
            $table->timestamp('subtitle_deadline_at')->nullable();
            $table->timestamp('subtitle_reviewed_at')->nullable();
            $table->timestamp('last_story_posted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_business_story_publications', function (Blueprint $table): void {
            $table->dropColumn([
                'source_media_path', 'subtitle_draft', 'subtitle_status', 'subtitle_worker',
                'subtitle_requested_at', 'subtitle_deadline_at', 'subtitle_reviewed_at',
                'last_story_posted_at',
            ]);
        });
    }
};
