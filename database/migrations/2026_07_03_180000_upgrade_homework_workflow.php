<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            if (! Schema::hasColumn('lessons', 'homework_opens_at')) {
                $table->timestamp('homework_opens_at')->nullable()->after('homework_attachments');
            }
            if (! Schema::hasColumn('lessons', 'homework_due_at')) {
                $table->timestamp('homework_due_at')->nullable()->after('homework_opens_at');
            }
            if (! Schema::hasColumn('lessons', 'homework_locks_at')) {
                $table->timestamp('homework_locks_at')->nullable()->after('homework_due_at');
            }
        });

        Schema::table('homework_submissions', function (Blueprint $table) {
            if (! Schema::hasColumn('homework_submissions', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('last_activity_at');
            }
            if (! Schema::hasColumn('homework_submissions', 'review_started_at')) {
                $table->timestamp('review_started_at')->nullable()->after('submitted_at');
            }
            if (! Schema::hasColumn('homework_submissions', 'accepted_at')) {
                $table->timestamp('accepted_at')->nullable()->after('reviewed_at');
            }
            if (! Schema::hasColumn('homework_submissions', 'locked_at')) {
                $table->timestamp('locked_at')->nullable()->after('accepted_at');
            }
            if (! Schema::hasColumn('homework_submissions', 'grade')) {
                $table->string('grade', 16)->nullable()->after('locked_at');
            }
        });

        DB::table('homework_submissions')
            ->where('status', 'needs_revision')
            ->update(['status' => 'needs_changes']);

        Schema::create('homework_deadline_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('locks_at')->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lesson_id', 'user_id']);
            $table->index(['lesson_id', 'group_id']);
        });

        Schema::create('homework_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->nullable()->constrained('homework_submissions')->cascadeOnDelete();
            $table->string('event');
            $table->string('channel')->default('workflow');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['lesson_id', 'user_id', 'submission_id', 'event', 'channel'], 'hw_notif_dedupe');
        });

        Schema::table('marketing_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('marketing_settings', 'homework_notifications_enabled')) {
                $table->boolean('homework_notifications_enabled')->default(true)->after('absent_notify_delay_minutes');
            }
            if (! Schema::hasColumn('marketing_settings', 'homework_notify_opened')) {
                $table->boolean('homework_notify_opened')->default(true)->after('homework_notifications_enabled');
            }
            if (! Schema::hasColumn('marketing_settings', 'homework_notify_deadline_near')) {
                $table->boolean('homework_notify_deadline_near')->default(true)->after('homework_notify_opened');
            }
            if (! Schema::hasColumn('marketing_settings', 'homework_notify_submitted')) {
                $table->boolean('homework_notify_submitted')->default(true)->after('homework_notify_deadline_near');
            }
            if (! Schema::hasColumn('marketing_settings', 'homework_notify_returned')) {
                $table->boolean('homework_notify_returned')->default(true)->after('homework_notify_submitted');
            }
            if (! Schema::hasColumn('marketing_settings', 'homework_notify_accepted')) {
                $table->boolean('homework_notify_accepted')->default(true)->after('homework_notify_returned');
            }
            if (! Schema::hasColumn('marketing_settings', 'homework_notify_locked')) {
                $table->boolean('homework_notify_locked')->default(true)->after('homework_notify_accepted');
            }
            if (! Schema::hasColumn('marketing_settings', 'homework_telegram_chat_id')) {
                $table->string('homework_telegram_chat_id')->nullable()->after('homework_notify_locked');
            }
            if (! Schema::hasColumn('marketing_settings', 'homework_vk_peer_id')) {
                $table->string('homework_vk_peer_id')->nullable()->after('homework_telegram_chat_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            foreach ([
                'homework_notifications_enabled', 'homework_notify_opened', 'homework_notify_deadline_near',
                'homework_notify_submitted', 'homework_notify_returned', 'homework_notify_accepted',
                'homework_notify_locked', 'homework_telegram_chat_id', 'homework_vk_peer_id',
            ] as $column) {
                if (Schema::hasColumn('marketing_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('homework_notification_logs');
        Schema::dropIfExists('homework_deadline_overrides');

        Schema::table('homework_submissions', function (Blueprint $table) {
            foreach (['submitted_at', 'review_started_at', 'accepted_at', 'locked_at', 'grade'] as $column) {
                if (Schema::hasColumn('homework_submissions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('lessons', function (Blueprint $table) {
            foreach (['homework_opens_at', 'homework_due_at', 'homework_locks_at'] as $column) {
                if (Schema::hasColumn('lessons', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
