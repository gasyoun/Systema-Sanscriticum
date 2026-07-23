<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1449 B2 — the "статус рассылки" ledger, one row per recipient per
 * campaign. `pixel_token` is the opaque identifier the tracking endpoints
 * resolve server-side (no PII in tracking URLs). `resend_of_id` links a
 * "догон" (resend-to-non-openers) recipient row back to the original send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('pixel_token')->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->foreignId('resend_of_id')->nullable()->constrained('campaign_recipients')->nullOnDelete();
            $table->timestamps();

            $table->index(['campaign_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
    }
};
