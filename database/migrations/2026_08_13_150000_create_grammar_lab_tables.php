<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grammar_lab_imports', function (Blueprint $table) {
            $table->id();
            $table->string('bundle_version', 32);
            $table->string('schema_version', 32);
            $table->string('manifest_sha256', 64);
            $table->string('bundle_sha256', 64);
            $table->unsignedInteger('published_topic_count')->default(0);
            $table->unsignedInteger('upserted_count')->default(0);
            $table->unsignedInteger('unchanged_count')->default(0);
            $table->unsignedInteger('retired_count')->default(0);
            $table->string('pin_tag', 64)->nullable();
            $table->timestamp('imported_at');
            $table->timestamps();
        });

        Schema::create('grammar_topics', function (Blueprint $table) {
            $table->id();
            $table->string('topic_id', 160)->unique();
            $table->string('slug', 120)->unique();
            $table->string('status', 32)->index();
            $table->string('title_ru');
            $table->string('cluster', 64)->nullable()->index();
            $table->string('difficulty', 32)->nullable();
            $table->text('summary_ru');
            $table->text('alignment_note')->nullable();
            $table->json('aliases')->nullable();
            $table->json('prerequisites')->nullable();
            $table->json('dictionary_evidence')->nullable();
            $table->json('corpus_evidence')->nullable();
            $table->json('exercise_ids')->nullable();
            $table->json('provenance')->nullable();
            $table->json('payload');
            $table->string('content_hash', 64)->index();
            $table->unsignedBigInteger('import_id')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
        });

        Schema::create('grammar_topic_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grammar_topic_id')->constrained('grammar_topics')->cascadeOnDelete();
            $table->string('topic_id', 160)->index();
            $table->string('family', 32)->index();
            $table->string('anchor_type', 64);
            $table->string('anchor_id', 160);
            $table->string('anchor_key_slp1', 80)->nullable();
            $table->string('source_dataset', 191);
            $table->string('link_type', 32)->nullable();
            $table->decimal('confidence', 4, 3)->nullable();
            $table->json('payload');
            $table->timestamps();
            $table->unique(['grammar_topic_id', 'anchor_type', 'anchor_id'], 'gl_topic_source_unique');
        });

        Schema::create('grammar_topic_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grammar_topic_id')->constrained('grammar_topics')->cascadeOnDelete();
            $table->string('topic_id', 160)->index();
            $table->string('content_hash', 64);
            $table->string('status', 32);
            $table->unsignedBigInteger('import_id')->nullable();
            $table->json('payload');
            $table->timestamp('recorded_at');
            $table->timestamps();
        });

        Schema::create('grammar_exercises', function (Blueprint $table) {
            $table->id();
            $table->string('exercise_id', 160)->unique();
            $table->string('topic_id', 160)->index();
            $table->string('risk_class', 32)->default('deterministic');
            $table->string('status', 32)->default('imported');
            $table->json('payload');
            $table->string('content_hash', 64);
            $table->timestamps();
        });

        Schema::create('grammar_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic_id', 160);
            $table->timestamps();
            $table->unique(['user_id', 'topic_id']);
        });

        Schema::create('grammar_topic_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic_id', 160);
            $table->timestamp('viewed_at');
            $table->timestamps();
            $table->index(['user_id', 'viewed_at']);
        });

        Schema::create('grammar_lab_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('grant_kind', 32);
            $table->string('source_ref', 191)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'grant_kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grammar_lab_entitlements');
        Schema::dropIfExists('grammar_topic_views');
        Schema::dropIfExists('grammar_bookmarks');
        Schema::dropIfExists('grammar_exercises');
        Schema::dropIfExists('grammar_topic_versions');
        Schema::dropIfExists('grammar_topic_sources');
        Schema::dropIfExists('grammar_topics');
        Schema::dropIfExists('grammar_lab_imports');
    }
};
