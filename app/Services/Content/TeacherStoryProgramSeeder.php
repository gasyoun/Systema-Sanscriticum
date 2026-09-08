<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCalendarSlot;
use App\Models\StoryPost;
use Illuminate\Support\Carbon;

/**
 * 4-week «Наши учителя» + «Истории учеников» seed (H4313, program wave W4).
 *
 * Source of truth: Uprava custdev/TEACHERS_AND_STORIES_CONTENT_PROGRAM_07-09-2026.md —
 * §1 teacher roster (real prod teacher IDs, first eight per MG cadence ruling),
 * §3 story candidates (real selected students). Cadence (MG, §0.4):
 * «2 учителя + 1 история в неделю».
 *
 * student_story slots carry NO real body until consent lands (program §4 red line):
 * they are seeded as HOLD placeholders (status kept, meta.consent = hold). Consent
 * ledger is the gate — never publish a story without an explicit «да».
 *
 * Every unit mirrors into story_posts (lane=channel) via the existing pipeline.
 * Publish stays flag-gated: content_calendar_autopilot stays false (repo default);
 * nothing here posts to live VK/TG.
 */
final class TeacherStoryProgramSeeder
{
    /** §1 roster: real prod teachers.id + verified samskrtam.ru page slugs. */
    private const TEACHERS = [
        ['id' => 1, 'name' => 'Гасунс Марцис Юрьевич', 'slug' => 'marcis-gasuns'],
        ['id' => 2, 'name' => 'Парибок Андрей', 'slug' => 'paribok'],
        ['id' => 3, 'name' => 'Костина Екатерина', 'slug' => 'ekaterina-kostina'],
        ['id' => 4, 'name' => 'Уша Санка', 'slug' => 'usha-sanka'],
        ['id' => 5, 'name' => 'Толчельников Иван', 'slug' => 'ivan-tolchelnikov'],
        ['id' => 6, 'name' => 'Трефилова Елена', 'slug' => 'elena-trefilova'],
        ['id' => 7, 'name' => 'Ворошилов Максим', 'slug' => 'maksim-voroshilov'],
        ['id' => 8, 'name' => 'Клебанов Андрей', 'slug' => 'andrey-klebanov'],
    ];

    /** §3 candidates 1–4 (only name + cohort hook; consent pending → HOLD). */
    private const STORIES = [
        ['n' => 1, 'name' => 'Петрова Рада', 'hook' => 'топ-1: Кочергина → 38 курсов'],
        ['n' => 2, 'name' => 'Цыди Анна', 'hook' => 'топ-2: напевный вход → грамматика'],
        ['n' => 3, 'name' => 'Шелест Юлия', 'hook' => 'Индия, Сказания о Нале первым'],
        ['n' => 5, 'name' => 'Чуракова Ангелина', 'hook' => 'вернулась после 27 мес'],
    ];

    private bool $lastMirrored = false;

    /**
     * Seed 4 weeks starting at the Monday of $startYear-$startMonth-$startDay.
     * Weeks: Mon/Wed teacher spotlight, Fri student story.
     *
     * @return array{created: int, mirrored: int, skipped: int}
     */
    public function seed(int $startYear, int $startMonth, int $startDay): array
    {
        $start = Carbon::create($startYear, $startMonth, $startDay, 0, 0, 0, 'Europe/Moscow');
        if ($start->dayOfWeekIso !== 1) {
            $start = $start->copy()->next(Carbon::MONDAY);
        }

        $created = 0;
        $mirrored = 0;

        foreach (self::TEACHERS as $i => $teacher) {
            // 2 per week: Monday and Wednesday of week intdiv(i, 2).
            $date = $start->copy()->addDays(7 * intdiv($i, 2) + 2 * ($i % 2));
            if ($this->seedTeacherSpotlight($date, $teacher)) {
                $created++;
                $mirrored += (int) ($this->lastMirrored ?? false);
            }
        }

        foreach (self::STORIES as $i => $story) {
            // 1 per week: Friday of week $i.
            $date = $start->copy()->addWeeks($i)->addDays(4);
            if ($this->seedStudentStory($date, $story)) {
                $created++;
                $mirrored += (int) ($this->lastMirrored ?? false);
            }
        }

        $skipped = count(self::TEACHERS) + count(self::STORIES) - $created;

        return ['created' => $created, 'mirrored' => $mirrored, 'skipped' => $skipped];
    }

    private function seedTeacherSpotlight(Carbon $date, array $teacher): bool
    {
        $existing = ContentCalendarSlot::query()
            ->where('slot_type', ContentCalendarSlot::TYPE_TEACHER_SPOTLIGHT)
            ->where('source_kind', 'teacher_program')
            ->where('source_ref', (string) $teacher['id'])
            ->first();
        if ($existing !== null) {
            $this->lastMirrored = false;

            return false;
        }
        $pageUrl = 'https://samskrtam.ru/'.$teacher['slug'].'/';
        $slot = ContentCalendarSlot::create([
            'channel' => ContentCalendarSlot::CHANNEL_VK_WALL,
            'slot_date' => $date->toDateString(),
            'slot_type' => ContentCalendarSlot::TYPE_TEACHER_SPOTLIGHT,
            'status' => ContentCalendarSlot::STATUS_SCHEDULED,
            'source_kind' => 'teacher_program',
            'source_ref' => (string) $teacher['id'],
            'title' => 'Спотлайт учителя: '.$teacher['name'],
            'body' => $this->teacherBody($teacher, $pageUrl),
            'meta' => [
                'teacher_id' => $teacher['id'],
                'teacher_name' => $teacher['name'],
                'page_url' => $pageUrl,
                'program' => 'teachers_and_stories',
            ],
        ]);

        $this->lastMirrored = $this->mirrorToStoryPost(
            $slot,
            'teacher-program-'.$teacher['id'],
            $slot->title,
            $slot->body,
            $date
        );

        return true;
    }

    private function seedStudentStory(Carbon $date, array $story): bool
    {
        $existing = ContentCalendarSlot::query()
            ->where('slot_type', ContentCalendarSlot::TYPE_STUDENT_STORY)
            ->where('source_kind', 'student_story_program')
            ->where('source_ref', (string) $story['n'])
            ->first();
        if ($existing !== null) {
            $this->lastMirrored = false;

            return false;
        }

        $slot = ContentCalendarSlot::create([
            'channel' => ContentCalendarSlot::CHANNEL_VK_WALL,
            'slot_date' => $date->toDateString(),
            'slot_type' => ContentCalendarSlot::TYPE_STUDENT_STORY,
            // Consent (program §4) is NOT yet on the ledger → HOLD, not scheduled.
            'status' => ContentCalendarSlot::STATUS_KEPT,
            'source_kind' => 'student_story_program',
            'source_ref' => (string) $story['n'],
            'title' => 'История ученика: '.$story['name'].' — HOLD (ждём согласие)',
            'body' => null,
            'meta' => [
                'story_candidate_no' => $story['n'],
                'student_name' => $story['name'],
                'hook' => $story['hook'],
                'consent' => 'hold',
                'program' => 'teachers_and_stories',
            ],
        ]);

        $this->lastMirrored = $this->mirrorToStoryPost(
            $slot,
            'student-story-program-'.$story['n'],
            $slot->title,
            null,
            $date
        );

        return true;
    }

    private function teacherBody(array $teacher, string $pageUrl): string
    {
        return sprintf(
            "«Наши учителя»: %s.\nСтраница преподавателя: %s\nКурсы и расписание: https://samskrtam.ru/obuchenie/",
            $teacher['name'],
            $pageUrl
        );
    }

    /**
     * Duplicate each seeded unit into story_posts (lane=channel) so the
     * existing stories:publish-due pipeline sees it. Text-only; draft
     * status; approved+publish_at stays a human/curation step.
     */
    private function mirrorToStoryPost(ContentCalendarSlot $slot, string $sourceKey, string $payload, ?string $body, Carbon $date): bool
    {
        $exists = StoryPost::query()
            ->where('source', StoryPost::SOURCE_QUEUE)
            ->where('source_key', $sourceKey)
            ->exists();
        if ($exists) {
            return false;
        }

        StoryPost::query()->create([
            'kind' => StoryPost::KIND_TEXT,
            'lane' => StoryPost::LANE_CHANNEL,
            'payload' => $payload.($body !== null ? "\n".$body : ''),
            'source' => StoryPost::SOURCE_QUEUE,
            'source_key' => $sourceKey,
            // Stories stay DRAFT: consent + editorial visa come before any publish.
            'status' => StoryPost::STATUS_DRAFT,
            'publish_at' => null,
            'journal' => now()->toDateTimeString()." H4313 program seed, календарный слот #{$slot->id}.",
        ]);

        return true;
    }
}
