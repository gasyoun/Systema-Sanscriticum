<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H445 Phase 4 (H546) — Day-2 mantra-reading voice submission (`deva`
 * cohort only). Voice notes land in a NON-public disk (never `public`) —
 * this is a private submission, not shareable media. `day2_voice_path` is
 * a relative path within that disk, not a public URL; served only via the
 * admin-only download route (RoleGate). `day2_voice_reviewed_at`/`_note`
 * are the paid-track curator lane (free track is self-assessed, no review).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->string('day2_voice_telegram_file_id')->nullable()->after('day2_question');
            $table->string('day2_voice_disk')->nullable()->after('day2_voice_telegram_file_id');
            $table->string('day2_voice_path')->nullable()->after('day2_voice_disk');
            $table->timestamp('day2_voice_received_at')->nullable()->after('day2_voice_path');
            $table->timestamp('day2_voice_reviewed_at')->nullable()->after('day2_voice_received_at');
            $table->text('day2_voice_curator_note')->nullable()->after('day2_voice_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('marathon_enrollments', function (Blueprint $table) {
            $table->dropColumn([
                'day2_voice_telegram_file_id',
                'day2_voice_disk',
                'day2_voice_path',
                'day2_voice_received_at',
                'day2_voice_reviewed_at',
                'day2_voice_curator_note',
            ]);
        });
    }
};
