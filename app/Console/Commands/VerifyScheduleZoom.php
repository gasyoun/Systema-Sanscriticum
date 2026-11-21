<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Schedule;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Zoom\ZoomService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H8495 — еженедельная сверка «расписание ↔ Zoom» как read-only гард.
 *
 * Тренд «авто-создание встреч из календаря» заимствован как ГАРД, без
 * авто-создания: команда читает занятия ближайших N дней и живым GET
 * /meetings/{id} проверяет каждый разрешённый zoom_meeting_id ДО того,
 * как напоминания недели уйдут студентам. Класс инцидента 04-09-2026:
 * фантомное «Скоро занятие» на серию, которую никто не создал (перенос
 * Zoom-повтора не занесли обратно в админку) — расписание живёт, встречи нет.
 *
 * Форма H1642 GC-B1: у курса ОДНА recurring-встреча (единый meeting_id на
 * все даты), поэтому id разрешается по цепочке занятие → курс → ссылки, а
 * GET делается один раз на уникальный id, не на каждое занятие.
 *
 * Категории вердикта:
 *  missing  — разрешить meeting_id не из чего: ни у занятия, ни у курса,
 *             ни на ссылках (класс «серия никогда не создана»);
 *  dead     — id разрешён, но GET вернул 404 на всех аккаунтах школы
 *             (серия удалена / id протух);
 *  mismatch — у занятия свой zoom_meeting_id, и он не равен id уровня
 *             курса (занятие указывает в другую комнату, чем анонсирует
 *             курс);
 *  unknown  — проверить не удалось (не-404 HTTP или обрыв сети): это НЕ
 *             «чисто», вердикт называет это вслух.
 *
 * ТОЛЬКО чтение: ни расписания, ни встречи команда не меняет — создание
 * недостающей встречи остаётся решением человека в админке (fail-вердикт
 * «auto-creation of Zoom meetings» для этой единицы запрещён handoff'ом).
 */
class VerifyScheduleZoom extends Command
{
    /** Синтетический id негативной канарейки: заведомо несуществующая встреча. */
    private const CANARY_MEETING_ID = '999999999999';

    protected $signature = 'zoom:verify-schedule
        {--days=7 : Сколько дней вперёд проверять}
        {--canary : Добавить синтетическое занятие с заведомо мёртвым id (негативная канарейка, только в вердикте)}
        {--notify : Отправить вердикт админам в Telegram}';

    protected $description = 'Сверка «расписание ↔ Zoom»: занятия ближайших N дней, живой GET встречи, вердикт missing/dead/mismatch (read-only, H8495)';

    public function handle(ZoomService $zoom, TelegramAdminNotifier $notifier): int
    {
        if (! $zoom->isConfigured()) {
            $this->warn('Zoom не сконфигурирован (ZOOM_ACCOUNT_ID/CLIENT_ID/CLIENT_SECRET) — сверка пропущена.');

            return self::SUCCESS;
        }

        $days = max(1, (int) $this->option('days'));
        $from = now();
        $to = now()->addDays($days);

        $lessons = Schedule::query()
            ->whereNotNull('start')
            ->whereBetween('start', [$from, $to])
            ->orderBy('start')
            ->get();

        // GET на уникальный id: recurring-серия курса одна на все даты (H1642).
        $statuses = [];

        $findings = [];

        foreach ($lessons as $lesson) {
            [$meetingId, $source] = $this->resolveMeetingId($lesson);

            if ($meetingId === null) {
                $findings[] = [
                    'category' => 'missing', 'schedule_id' => $lesson->id,
                    'course_id' => $lesson->course_id, 'date' => $lesson->start->toDateString(),
                    'meeting_id' => null, 'http' => null,
                ];

                continue;
            }

            $statuses[$meetingId] ??= $zoom->getMeeting($meetingId);
            $status = $statuses[$meetingId];

            if ($status['status'] === 'not_found') {
                $findings[] = [
                    'category' => 'dead', 'schedule_id' => $lesson->id,
                    'course_id' => $lesson->course_id, 'date' => $lesson->start->toDateString(),
                    'meeting_id' => $meetingId, 'http' => $status['http'],
                ];

                continue;
            }

            if ($status['status'] === 'error') {
                $findings[] = [
                    'category' => 'unknown', 'schedule_id' => $lesson->id,
                    'course_id' => $lesson->course_id, 'date' => $lesson->start->toDateString(),
                    'meeting_id' => $meetingId, 'http' => $status['http'],
                ];

                continue;
            }

            // Встреча жива. Mismatch: у занятия СВОЙ id, и он указывает в
            // другую комнату, чем id уровня курса (перенос серии не занесли
            // в админку, либо занятие привязали к чужой комнате).
            if ($source === 'schedules.zoom_meeting_id') {
                $courseLevel = $this->courseLevelMeetingId($lesson->course);
                if ($courseLevel !== null && $courseLevel !== $meetingId) {
                    $findings[] = [
                        'category' => 'mismatch', 'schedule_id' => $lesson->id,
                        'course_id' => $lesson->course_id, 'date' => $lesson->start->toDateString(),
                        'meeting_id' => $meetingId, 'http' => null,
                    ];
                }
            }
        }

        if ($this->option('canary')) {
            // Негативная канарейка (H8495): синтетическое занятие с заведомо
            // несуществующей встречей. Существует только в этом прогоне —
            // ни одна строка БД не пишется и не правится.
            $statuses[self::CANARY_MEETING_ID] ??= $zoom->getMeeting(self::CANARY_MEETING_ID);
            $canaryStatus = $statuses[self::CANARY_MEETING_ID];
            $findings[] = [
                'category' => $canaryStatus['status'] === 'ok' ? 'unknown' : ($canaryStatus['status'] === 'error' ? 'unknown' : $canaryStatus['status']),
                'schedule_id' => 0, 'course_id' => 0,
                'date' => now()->addDay()->toDateString(),
                'meeting_id' => self::CANARY_MEETING_ID, 'http' => $canaryStatus['http'],
                'canary' => true,
            ];
        }

        $this->renderReport($lessons->count(), $findings, $from, $to);
        $this->emitVerdict($lessons->count(), $findings, $from, $to, (bool) $this->option('notify'), $notifier);

        return self::SUCCESS;
    }

    /**
     * Разрешение meeting_id занятия: поле занятия → id курса → ссылка курса
     * → ссылка занятия. Та же цепочка, что у H3761 W3; форма GC-B1 ожидает
     * один recurring id на курс, а не per-lesson id.
     *
     * @return array{0: string|null, 1: string}
     */
    private function resolveMeetingId(Schedule $lesson): array
    {
        if (! empty($lesson->zoom_meeting_id)) {
            return [(string) $lesson->zoom_meeting_id, 'schedules.zoom_meeting_id'];
        }

        $course = $lesson->course;
        if ($course !== null) {
            if (! empty($course->zoom_meeting_id)) {
                return [(string) $course->zoom_meeting_id, 'courses.zoom_meeting_id'];
            }

            if (preg_match('~/j/(\d+)~', (string) $course->zoom_link, $m)) {
                return [$m[1], 'courses.zoom_link'];
            }
        }

        if (preg_match('~/j/(\d+)~', (string) $lesson->link, $m)) {
            return [$m[1], 'schedules.link'];
        }

        return [null, 'не разрешен'];
    }

    /** Id уровня курса (карточка или её ссылка) — база для mismatch-сравнения. */
    private function courseLevelMeetingId(?Course $course): ?string
    {
        if ($course === null) {
            return null;
        }

        if (! empty($course->zoom_meeting_id)) {
            return (string) $course->zoom_meeting_id;
        }

        if (preg_match('~/j/(\d+)~', (string) $course->zoom_link, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param  list<array{category: string, schedule_id: int, course_id: int|null, date: string, meeting_id: string|null, http: int|null, canary?: bool}>  $findings
     */
    private function renderReport(int $lessonCount, array $findings, $from, $to): void
    {
        $byCategory = ['missing' => 0, 'dead' => 0, 'mismatch' => 0, 'unknown' => 0];
        foreach ($findings as $f) {
            $byCategory[$f['category']] = ($byCategory[$f['category']] ?? 0) + 1;
        }

        $this->info(sprintf(
            'Сверка «расписание ↔ Zoom» (H8495): занятий %d за %s..%s, findings: missing=%d, dead=%d, mismatch=%d, unknown=%d.',
            $lessonCount,
            $from->format('d.m'),
            $to->format('d.m'),
            $byCategory['missing'],
            $byCategory['dead'],
            $byCategory['mismatch'],
            $byCategory['unknown'],
        ));

        if ($findings === []) {
            return;
        }

        $this->warn('Findings (все категории — человек решает, машина только называет):');
        foreach ($findings as $f) {
            $who = ($f['canary'] ?? false)
                ? 'канарейка (синтетическое занятие)'
                : sprintf('schedule#%d курс#%s %s', $f['schedule_id'], (string) $f['course_id'], $f['date']);
            $this->line(sprintf('  • %s — %s%s', $who, $f['category'], $f['meeting_id'] !== null ? ' (meeting '.$f['meeting_id'].')' : ''));
        }
    }

    /**
     * Вердикт-строка в консоль и лог всегда, в Telegram — по --notify
     * (fail-open). На чистой неделе вердикт содержит literal «0 findings».
     * В строках нет имён студентов — только id расписания/курса и даты.
     */
    private function emitVerdict(int $lessonCount, array $findings, $from, $to, bool $notify, TelegramAdminNotifier $notifier): void
    {
        $byCategory = ['missing' => 0, 'dead' => 0, 'mismatch' => 0, 'unknown' => 0];
        foreach ($findings as $f) {
            $byCategory[$f['category']] = ($byCategory[$f['category']] ?? 0) + 1;
        }
        $total = count($findings);

        $summary = sprintf(
            'Вердикт сверки (H8495): занятий %d за %s..%s, findings: missing=%d, dead=%d, mismatch=%d, unknown=%d → %s.',
            $lessonCount,
            $from->format('d.m'),
            $to->format('d.m'),
            $byCategory['missing'],
            $byCategory['dead'],
            $byCategory['mismatch'],
            $byCategory['unknown'],
            $total === 0 ? '0 findings' : "findings={$total}",
        );

        $this->line($summary);

        $details = array_map(function (array $f): string {
            $who = ($f['canary'] ?? false)
                ? 'канарейка (синтетическое занятие)'
                : sprintf('schedule#%d курс#%s %s', $f['schedule_id'], (string) $f['course_id'], $f['date']);

            return '• '.$who.' — '.$f['category'].($f['meeting_id'] !== null ? ' (meeting '.$f['meeting_id'].')' : '');
        }, $findings);

        Log::info('zoom.schedule_verify_verdict', [
            'lessons' => $lessonCount,
            'total_findings' => $total,
            'missing' => $byCategory['missing'],
            'dead' => $byCategory['dead'],
            'mismatch' => $byCategory['mismatch'],
            'unknown' => $byCategory['unknown'],
            'window' => [$from->toDateString(), $to->toDateString()],
        ]);

        if (! $notify) {
            return;
        }

        $text = $total === 0
            ? '✅ '.$summary
            : '⚠️ '.$summary."\n".implode("\n", $details)."\n\nНедостающая/мертвая встреча создаётся или перепривязывается человеком в админке — авто-создания нет (H8495).";

        try {
            $delivered = $notifier->notifyAdmins($text);

            if ($delivered === []) {
                Log::warning('zoom.schedule_verify_verdict_undelivered', ['reason' => 'tg not configured or send failed']);
                $this->comment('TG-вердикт не доставлен (нет настроенных получателей или сбой отправки).');
            } else {
                Log::info('zoom.schedule_verify_verdict_delivered', ['recipients' => count($delivered)]);
                $this->comment('TG-вердикт доставлен получателям: '.count($delivered).'.');
            }
        } catch (Throwable $e) {
            Log::warning('zoom.schedule_verify_verdict_send_failed', [
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
    }
}
