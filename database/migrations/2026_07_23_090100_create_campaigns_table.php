<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1449 B2 — one рассылка. Additive, behind the `email_campaigns` flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->longText('body_html');
            // Segment filter, e.g. {"type":"all"} / {"type":"course","course_id":5} / {"type":"lead_stage","stage":"new"}.
            $table->json('segment')->nullable();
            // draft | sending | sent
            $table->string('status')->default('draft');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
