<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H5049 — anons publishing v2: declarative manifests, idempotent publication
 * state, per-destination runs, explicit-missingness metrics, archive catalog
 * and /ga/ click counting (previously redirect-only, never recorded).
 *
 * PII note: anons_link_clicks stores NO personal data — only the short-link
 * slug, the resolved UTM tuple and a timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anons_publications', function (Blueprint $table): void {
            $table->id();
            // Idempotency: sha256(campaign|creative|destination-set|slot).
            // One row per manifest application; rerun resolves here first.
            $table->string('publication_key', 64)->unique();
            $table->string('campaign_id', 64)->index();
            $table->string('creative_id', 64)->index();
            $table->string('slot', 64); // schedule bucket or frame index
            $table->string('manifest_hash', 64); // sha256 of canonical manifest
            $table->json('manifest'); // immutable accepted artifact
            $table->boolean('test_mode')->default(false);
            $table->unsignedInteger('frame_total')->default(1);
            // draft | ready | publishing | published | partial | failed | rolled_back
            $table->string('status', 16)->default('draft')->index();
            $table->text('journal')->nullable();
            $table->timestamps();
        });

        Schema::create('anons_destination_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('anons_publication_id')->constrained()->cascadeOnDelete();
            // "telegram_story@rusamskrtam" — one row per account/platform.
            $table->string('destination', 96);
            $table->string('platform', 32); // telegram_story | telegram_post | vk | senler
            $table->string('account', 64);
            $table->unsignedInteger('frame_index')->default(0);
            // pending | running | published | failed | blocked
            $table->string('state', 16)->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $table->json('remote_ids')->nullable(); // [frame => telegram story id]
            $table->string('content_hash', 64)->nullable(); // rendered artifact hash
            $table->string('artifact_path')->nullable(); // rendered image evidence
            $table->string('short_link', 255)->nullable(); // /ga/ slug
            $table->json('utm')->nullable(); // full UTM tuple
            $table->timestamps();
            // Имя короче 64 символов — лимит идентификаторов MySQL.
            $table->unique(['anons_publication_id', 'destination', 'frame_index'], 'anons_runs_pub_dest_frame_unique');
        });

        // Explicit missingness: an absent API field is NEVER numeric zero.
        Schema::create('anons_metrics', function (Blueprint $table): void {
            $table->id();
            $table->string('publication_key', 64)->index();
            $table->string('destination', 96);
            $table->string('metric', 32); // views | reactions | forwards | link_clicks
            // value | unavailable | not_supported | pending | failed
            $table->string('state', 16)->default('pending');
            $table->unsignedBigInteger('value')->nullable();
            $table->json('utm')->nullable(); // UTM tuple binding
            $table->timestamp('observed_at');
            $table->timestamps();
            $table->unique(['publication_key', 'destination', 'metric', 'observed_at'], 'anons_metrics_dedupe');
        });

        // Indexed archive catalog: find an old evergreen asset WITHOUT
        // rescanning Telegram history every time.
        Schema::create('anons_archive_items', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 32);
            $table->string('account', 64);
            $table->string('remote_id', 64); // story id / message id
            $table->timestamp('captured_at')->nullable();
            $table->string('media_hash', 64)->nullable(); // sha256 of media bytes
            $table->string('phash', 32)->nullable(); // 64-bit dhash hex
            $table->json('dimensions')->nullable(); // {w,h}
            $table->text('text')->nullable(); // extracted caption
            $table->json('tags')->nullable();
            $table->string('destination_url', 512)->nullable();
            $table->timestamp('expires_at')->nullable(); // story expiry / currentness
            $table->json('usage_history')->nullable(); // publication keys
            $table->string('media_path')->nullable();
            $table->timestamps();
            $table->unique(['platform', 'account', 'remote_id']);
            $table->index('media_hash');
        });

        // /ga/ redirect clicks (H5049 R6): the counter that did not exist.
        Schema::create('anons_link_clicks', function (Blueprint $table): void {
            $table->id();
            $table->string('link', 128)->index(); // /ga/ slug
            $table->string('publication_key', 64)->nullable()->index();
            $table->json('utm')->nullable();
            $table->timestamp('clicked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anons_link_clicks');
        Schema::dropIfExists('anons_archive_items');
        Schema::dropIfExists('anons_metrics');
        Schema::dropIfExists('anons_destination_runs');
        Schema::dropIfExists('anons_publications');
    }
};
