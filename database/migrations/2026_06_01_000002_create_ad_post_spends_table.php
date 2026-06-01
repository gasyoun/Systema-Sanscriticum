<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_post_spends', function (Blueprint $table) {
            $table->id();
            $table->date('posted_on')->index();
            $table->string('title');
            $table->decimal('budget', 12, 2)->default(0);
            $table->unsignedInteger('writers_count')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_post_spends');
    }
};
