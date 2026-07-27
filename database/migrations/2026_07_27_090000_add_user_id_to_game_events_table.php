<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1680 — SRS onboarding needs to know which authenticated user a batch of
 * anonymous game_events belongs to, so free-play "seen" packs can be turned
 * into an onboarding SRS deck on first login. Nullable, set only once a
 * session is authenticated (GameTelemetryController stamps it on write;
 * GamesOnboardingImporter backfills prior anon_id rows on first login).
 *
 * This does NOT reopen 152-FZ scope on `anon_id` itself (still a random
 * client token, still no IP/user-agent stored) — it only lets an already-
 * authenticated user's OWN prior anonymous rows be linked to their OWN
 * account, the same way any other authenticated table in this app already
 * carries user_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_events', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('anon_id')->constrained()->nullOnDelete();
            $table->index(['user_id', 'event']);
        });
    }

    public function down(): void
    {
        Schema::table('game_events', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'event']);
            $table->dropColumn('user_id');
        });
    }
};
