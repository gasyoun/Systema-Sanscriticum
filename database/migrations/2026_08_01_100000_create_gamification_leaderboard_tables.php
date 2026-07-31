<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2052 — multi-board gamification.
 *
 * - memrise_leaderboard_imports: snapshot of Memrise course leaderboards (points transfer)
 * - lila_score_events: authenticated /lila scores (game_events stay anonymous per H1360)
 * - users.memrise_username: optional link to claim imported points
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memrise_leaderboard_imports', function (Blueprint $table) {
            $table->id();
            $table->string('course_id', 32)->index();
            $table->string('username', 120);
            $table->unsignedInteger('points')->default(0);
            $table->string('period', 16)->default('all'); // all|week|month snapshot label
            $table->unsignedInteger('rank')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('imported_at')->useCurrent();
            $table->unique(['course_id', 'username', 'period'], 'memrise_lb_course_user_period');
        });

        Schema::create('lila_score_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('drill', 40)->index();
            $table->string('band', 40)->nullable();
            $table->unsignedInteger('points')->default(10);
            $table->string('event', 32)->default('complete');
            $table->timestamp('created_at')->useCurrent()->index();
            $table->index(['user_id', 'created_at']);
            $table->index(['drill', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'memrise_username')) {
                $table->string('memrise_username', 120)->nullable()->after('email');
                $table->unique('memrise_username');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'memrise_username')) {
                $table->dropUnique(['memrise_username']);
                $table->dropColumn('memrise_username');
            }
        });
        Schema::dropIfExists('lila_score_events');
        Schema::dropIfExists('memrise_leaderboard_imports');
    }
};
