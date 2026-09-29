<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_business_story_publications', function (Blueprint $table): void {
            $table->string('cta_url', 512)->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('deferred_until')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamp('metrics_collected_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_business_story_publications', function (Blueprint $table): void {
            $table->dropIndex(['started_at']);
            $table->dropColumn(['cta_url', 'started_at', 'deferred_until', 'metrics', 'metrics_collected_at']);
        });
    }
};
