<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_business_story_publications', function (Blueprint $table) {
            $table->id();
            $table->string('source_chat_id');
            $table->unsignedBigInteger('source_message_id');
            $table->string('telegram_file_unique_id')->nullable();
            $table->char('media_sha256', 64)->nullable();
            $table->foreignId('duplicate_of_id')->nullable()->constrained('telegram_business_story_publications');
            $table->unsignedBigInteger('story_id')->nullable();
            $table->string('status', 24)->default('received');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['source_chat_id', 'source_message_id'], 'tg_business_story_source_message_unique');
            $table->unique('media_sha256', 'tg_business_story_media_sha256_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_business_story_publications');
    }
};
