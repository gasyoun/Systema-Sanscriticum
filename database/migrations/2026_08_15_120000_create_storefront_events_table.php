<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2762 — first-party storefront experiment events (R12 R15).
 *
 * ActivityEvent cannot store guests (user_id NOT NULL). GameEvent is drills.
 * This table is the small shop logger after those three routes.
 * No IP, no user-agent. visitor_id is an opaque cookie token.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storefront_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_name', 40);
            $table->string('experiment', 20);
            $table->string('variant', 8)->nullable();
            $table->string('flagship_key', 40);
            $table->unsignedBigInteger('course_id')->nullable();
            $table->string('target', 40)->nullable();
            $table->string('visitor_id', 32)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_name', 'created_at']);
            $table->index(['experiment', 'variant', 'created_at']);
            $table->index(['visitor_id', 'event_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storefront_events');
    }
};
