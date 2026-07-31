<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2010 — real per-visitor A/B split for MarathonLandingCopy (copy variant,
 * distinct from MarathonVisual's skin axis). Appends one row per newly
 * assigned visitor (`impression`) and one per resulting Lead (`lead`), so
 * MarathonLandingCopyReport can compute impressions/leads/conversion rate
 * per variant. Test-until 2026-11-01 (MG ruling, 31-07-2026) — a human
 * reviews the numbers after that date, this table does not auto-decide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marathon_landing_copy_variant_events', function (Blueprint $table) {
            $table->id();
            $table->string('variant', 1);
            $table->string('event_type', 20); // impression | lead
            $table->timestamp('created_at')->useCurrent();

            $table->index(['variant', 'event_type']);
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->string('landing_copy_variant', 1)->nullable()->after('landing_page_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('landing_copy_variant');
        });

        Schema::dropIfExists('marathon_landing_copy_variant_events');
    }
};
