<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SupportTopicRule;
use Illuminate\Database\Seeder;

/**
 * H2320 — optional rollup topic for homework-pause keywords (analytics only).
 * Student CRM note is written by HomeworkPauseNoteRecorder, not by this rule.
 */
class HomeworkPauseTopicRuleSeeder extends Seeder
{
    public function run(): void
    {
        SupportTopicRule::query()->updateOrCreate(
            ['category' => 'homework_pause'],
            [
                'keywords' => [
                    'домашк',
                    'застой',
                    'больничн',
                    'выбило из колеи',
                    'догоню',
                ],
                'priority' => 40,
                'is_enabled' => true,
            ],
        );
    }
}
