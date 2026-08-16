<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_access_verdicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->string('surface', 32);
            $table->string('mode', 24);
            $table->boolean('would_allow');
            $table->string('reason', 64);
            $table->timestamp('requested_at')->index();
            $table->index(['would_allow', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_access_verdicts');
    }
};
