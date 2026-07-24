<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1564 (VK/ORS content calendar W1): one row per planned wall slot.
 * Additive; gated by features.content_calendar for UI/commands only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_calendar_slots', function (Blueprint $table) {
            $table->id();
            $table->string('channel')->default('vk_wall');
            $table->date('slot_date');
            $table->string('slot_type')->default('empty');
            $table->string('status')->default('empty');
            $table->timestamp('publish_at')->nullable();
            $table->string('source_kind')->nullable();
            $table->string('source_ref')->nullable();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['channel', 'slot_date']);
            $table->index(['status', 'publish_at']);
            $table->index(['source_kind', 'source_ref']);
        });

        Schema::table('content_candidates', function (Blueprint $table) {
            $table->foreignId('calendar_slot_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('content_calendar_slots')
                ->nullOnDelete();
            $table->index('calendar_slot_id');
        });
    }

    public function down(): void
    {
        Schema::table('content_candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('calendar_slot_id');
        });
        Schema::dropIfExists('content_calendar_slots');
    }
};
