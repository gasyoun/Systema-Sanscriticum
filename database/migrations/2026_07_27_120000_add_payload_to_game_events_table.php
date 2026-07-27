<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1680 — Wave 2: seen-item payload for the new `item_seen` event, feeding
 * the onboarding-from-games SRS import. Additive only — no user_id/guest_id
 * column: game_events stays outside the 152-FZ perimeter (see the create
 * migration and GamesFunnelTelemetryTest::the_table_stores_no_ip_or_user_agent_by_design).
 * The onboarding import personalises off the existing `anon_id` instead,
 * read back only while the requesting user is authenticated (SrsOnboardingFromGames).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_events', function (Blueprint $table): void {
            // {"items": [{"iast": "...", "ru": "..."}, ...]} — sanitized, no PII.
            $table->json('payload')->nullable()->after('event');
        });
    }

    public function down(): void
    {
        Schema::table('game_events', function (Blueprint $table): void {
            $table->dropColumn('payload');
        });
    }
};
