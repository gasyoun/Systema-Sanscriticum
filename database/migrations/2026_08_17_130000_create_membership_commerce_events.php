<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_funnel_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_name', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tariff_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_hash', 64)->nullable();
            $table->string('tier', 16)->nullable();
            $table->unsignedTinyInteger('term_months')->nullable();
            $table->string('source_site', 32);
            $table->string('archive_key', 48)->nullable();
            $table->string('feature', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_name', 'created_at']);
            $table->index(['source_site', 'tier', 'term_months']);
            $table->index(['course_id', 'feature']);
            $table->unique(['event_name', 'payment_id'], 'membership_funnel_payment_once');
        });

        Schema::create('private_archive_controls', function (Blueprint $table): void {
            $table->string('archive_key', 48)->primary();
            $table->timestamp('disabled_at')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('private_archive_access_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('archive_key', 48);
            $table->string('action', 32);
            $table->string('reason', 64);
            $table->string('request_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['archive_key', 'action', 'created_at'], 'archive_access_action_created');
            $table->index(['user_id', 'archive_key'], 'archive_access_user_archive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_archive_access_events');
        Schema::dropIfExists('private_archive_controls');
        Schema::dropIfExists('membership_funnel_events');
    }
};
