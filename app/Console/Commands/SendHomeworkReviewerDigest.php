<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Filament\Resources\HomeworkSubmissionResource;
use App\Mail\HomeworkReviewerDigestMail;
use App\Models\Course;
use App\Models\Group;
use App\Models\HomeworkSubmission;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Недельная сводка преподавателю курса по работам, которые за него проверяет
 * проверяющий по гранту (H1729).
 *
 * Смысл — не потерять видимость потока: персональные письма по каждой работе у
 * такого курса уходят проверяющему, а преподаватель раз в неделю получает
 * «сдано / проверено / ещё ждёт». Курсы без проверяющих сюда не попадают вовсе
 * и продолжают получать письмо на каждую работу, как раньше.
 *
 * Пустую сводку не шлём: тишина за неделю — это тоже сигнал, но не повод писать.
 */
class SendHomeworkReviewerDigest extends Command
{
    protected $signature = 'homework:reviewer-digest
        {--dry : Только показать сводку, без отправки писем}
        {--days=7 : Окно сводки в днях}';

    protected $description = 'Недельная сводка преподавателю по домашкам, которые проверяет проверяющий (запускать раз в неделю).';

    public function handle(): int
    {
        if (! config('homework.reviewers.enabled') || ! config('homework.reviewers.digest_enabled')) {
            $this->info('Сводка выключена в config/homework.php — выходим.');

            return self::SUCCESS;
        }

        $since = now()->subDays(max(1, (int) $this->option('days')));
        $sent = 0;

        foreach ($this->coursesWithReviewers() as $courseId => $reviewerNames) {
            $course = Course::with('teacher')->find($courseId);
            if (! $course) {
                continue;
            }

            $summary = $this->summaryFor($course, $since, $reviewerNames);

            if ($summary['submitted'] === 0 && $summary['waiting'] === 0) {
                continue;
            }

            $this->line(sprintf(
                '%s — сдано %d, проверено %d, ждёт %d',
                $course->title,
                $summary['submitted'],
                $summary['reviewed'],
                $summary['waiting'],
            ));

            $email = $course->teacher?->email;
            if (! $email) {
                $this->warn("  у курса #{$course->id} нет email преподавателя — пропуск");

                continue;
            }

            if (! $this->option('dry')) {
                Mail::to($email)->send(new HomeworkReviewerDigestMail($course, $summary, $this->queueUrl()));
            }
            $sent++;
        }

        $this->info($this->option('dry') ? "Сухой прогон: писем было бы {$sent}." : "Отправлено писем: {$sent}.");

        return self::SUCCESS;
    }

    /**
     * Курсы, у групп которых есть проверяющие, вместе с их именами.
     *
     * @return array<int, string>
     */
    private function coursesWithReviewers(): array
    {
        $map = [];

        $groups = Group::has('reviewers')->with(['reviewers', 'courses'])->get();

        foreach ($groups as $group) {
            // Только те, у кого notify = true: письмо преподавателю подавляется
            // ровно для них, значит и сводка положена ровно по ним. Иначе курс
            // получал бы и письмо на каждую работу, и сводку.
            $names = $group->reviewers
                ->filter(fn ($reviewer) => (bool) $reviewer->pivot->notify)
                ->pluck('name')->filter()->unique()->values()->all();
            if ($names === []) {
                continue;
            }
            foreach ($group->courses as $course) {
                $map[(int) $course->id] = array_values(array_unique(array_merge($map[(int) $course->id] ?? [], $names)));
            }
        }

        return array_map(fn (array $names) => implode(', ', $names), $map);
    }

    /**
     * @return array{submitted:int, reviewed:int, waiting:int, waiting_rows:array<int, array<string, string>>, reviewers:string}
     */
    private function summaryFor(Course $course, Carbon $since, string $reviewerNames): array
    {
        $base = HomeworkSubmission::where('course_id', $course->id)
            ->where('status', '!=', HomeworkSubmission::STATUS_DRAFT);

        $submitted = (clone $base)->where('last_activity_at', '>=', $since)->count();

        $reviewed = (clone $base)
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '>=', $since)
            ->count();

        $waitingQuery = (clone $base)
            ->where('status', HomeworkSubmission::STATUS_SUBMITTED)
            ->orderBy('last_activity_at');

        $waitingRows = (clone $waitingQuery)
            ->with(['user', 'lesson'])
            ->limit(10)
            ->get()
            ->map(fn (HomeworkSubmission $s) => [
                'student' => $s->user?->name ?: 'Студент',
                'lesson' => $s->lesson?->title ?: '—',
                'waiting_since' => $s->last_activity_at?->diffForHumans() ?: '—',
            ])
            ->all();

        return [
            'submitted' => $submitted,
            'reviewed' => $reviewed,
            'waiting' => (clone $waitingQuery)->count(),
            'waiting_rows' => $waitingRows,
            'reviewers' => $reviewerNames,
        ];
    }

    private function queueUrl(): string
    {
        $resource = HomeworkSubmissionResource::class;

        return class_exists($resource) ? $resource::getUrl('index') : url('/admin');
    }
}
