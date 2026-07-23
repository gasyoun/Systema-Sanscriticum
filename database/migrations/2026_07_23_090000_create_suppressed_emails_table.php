<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1449 A2 — hard-bounce / unsubscribe suppression list. Additive, no
 * relation to any existing table: App\Listeners\Email\EnforceMailSendingGuards
 * checks every outgoing message's recipients against this table and cancels
 * the send (never queues/retries) if any recipient is present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppressed_emails', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            // hard_bounce | unsubscribe | manual | complaint
            $table->string('reason');
            $table->timestamp('suppressed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppressed_emails');
    }
};
