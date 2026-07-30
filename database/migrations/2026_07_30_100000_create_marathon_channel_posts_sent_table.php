<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marathon_channel_posts_sent', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('post_number');
            // 'once' for one-shot posts (1, 2, 4, 5); an ISO year-week (e.g. "2026-W36")
            // for the recurring evergreen post 3 -- lets it re-fire every week while still
            // deduping a scheduler double-run within the same week.
            $table->string('run_key');
            $table->timestamp('sent_at');
            $table->unique(['post_number', 'run_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marathon_channel_posts_sent');
    }
};
