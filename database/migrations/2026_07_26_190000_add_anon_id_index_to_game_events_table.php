<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1678 — CTA->register KPI (`GameEvent::ctaRegistrationRate()`) looks up
 * every `anon_id` from the CTA-click set individually; index it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_events', function (Blueprint $table): void {
            $table->index(['anon_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('game_events', function (Blueprint $table): void {
            $table->dropIndex(['anon_id', 'created_at']);
        });
    }
};
