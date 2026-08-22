<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Аудит рассылок статуса листу ожидания (H3327): кто, когда, какой текст,
 * сколько доставлено мессенджером и сколько осталось на ручной обход.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_outreaches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->string('kind', 32);
            $table->text('text');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('messengers_count')->default(0);
            $table->unsignedInteger('manual_count')->default(0);
            $table->timestamps();

            $table->index(['group_id', 'kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_outreaches');
    }
};
