<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->string('course_id')->index();
            $table->string('title');
            $table->date('lesson_date');
            $table->string('video_url')->nullable();
            $table->string('rutube_url')->nullable();
            $table->text('topic')->nullable();
            $table->json('flash_cards')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};