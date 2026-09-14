<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\User;
use App\Models\WebinarParticipantLink;
use App\Support\InsertOnlyGuard;
use App\Support\ZoomNameMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * H3761 — сопоставление экранных имён Zoom с плательщиками курса.
 *
 * Собранная посещаемость есть, но она ни к кому не привязана: почта приходит
 * у 4 % участников, поэтому у 96 % строк `webinar_attendances.user_id` пуст, и
 * плашка покрытия честно показывает ноль живых людей при сотнях строк.
 *
 * Команда НЕ трогает `webinar_attendances` — она только заводит связки в
 * отдельной таблице (`webinar_participant_links`), и только вставками. Уже
 * существующая связка (в том числе подтверждённая человеком) не переписывается
 * никогда: `--apply` повторно — no-op.
 *
 * Неоднозначное и несопоставленное не угадывается. Такие имена печатаются
 * списком: их разбирает человек, потому что «этот ник — этот студент» —
 * решение об атрибуции, а не арифметика.
 */
class LinkWebinarParticipants extends Command
{
    protected $signature = 'attendance:link-participants
        {--course=* : ID курса; можно повторять. Без опции — все курсы, где есть посещаемость}
        {--weak : Заводить и связки по одному совпавшему слову (по умолчанию только имя+фамилия)}
        {--fuzzy : Заводить и нечёткие связки — складка транслита, уменьшительные, опечатка в допуске (H3772)}
        {--apply : Записать связки (без опции — сухой прогон)}';

    protected $description = 'Связать экранные имена участников Zoom с плательщиками курса — только вставки, webinar_attendances не меняется';

    public function handle(): int
    {
        $courses = $this->targetCourses();
        if ($courses->isEmpty()) {
            $this->warn('Курсов с посещаемостью не найдено.');

            return self::SUCCESS;
        }

        $planned = [];
        $needHuman = [];

        foreach ($courses as $course) {
            $candidates = User::query()
                ->join('course_user', 'course_user.user_id', '=', 'users.id')
                ->where('course_user.course_id', $course->id)
                ->pluck('users.name', 'users.id')
                ->all();

            if ($candidates === []) {
                continue;
            }

            $zoomNames = DB::table('webinar_attendances as wa')
                ->join('schedules as s', 's.id', '=', 'wa.schedule_id')
                ->where('s.course_id', $course->id)
                ->whereNotNull('wa.name')
                ->distinct()
                ->pluck('wa.name')
                ->all();

            $linked = WebinarParticipantLink::where('course_id', $course->id)
                ->pluck('zoom_name_key')
                ->flip();

            $seenKeys = [];

            foreach ($zoomNames as $zoomName) {
                $key = ZoomNameMatcher::key($zoomName);
                if ($key === '' || $linked->has($key) || isset($seenKeys[$key])) {
                    continue;
                }

                $result = ZoomNameMatcher::match($zoomName, $candidates);
                $accept = $result['user_id'] !== null && match ($result['confidence']) {
                    'strong' => true,
                    'weak' => (bool) $this->option('weak'),
                    'fuzzy' => (bool) $this->option('fuzzy'),
                    default => false,
                };

                if ($accept) {
                    $seenKeys[$key] = true;
                    $planned[] = [
                        'course_id' => $course->id,
                        'user_id' => $result['user_id'],
                        'zoom_name' => (string) $zoomName,
                        'zoom_name_key' => $key,
                        'confidence' => $result['confidence'],
                        // Не пишется в БД — только для отчёта: нечёткую связку
                        // человек должен иметь возможность проглядеть глазами.
                        'reason' => $result['reason'],
                        'candidate' => $candidates[$result['user_id']] ?? '—',
                    ];

                    continue;
                }

                $needHuman[] = [
                    $course->id,
                    (string) $zoomName,
                    $result['confidence'] ?? '—',
                    $result['reason'],
                ];
            }
        }

        $this->info('Связки к заведению:');
        $this->table(
            ['Курс', 'Имя в Zoom', 'Кандидат в кабинете', 'Уверенность', 'Почему'],
            array_map(
                fn (array $p) => [
                    $p['course_id'],
                    $p['zoom_name'],
                    mb_substr((string) $p['candidate'], 0, 42),
                    $p['confidence'],
                    $p['reason'],
                ],
                $planned
            ) ?: [['—', '—', '—', '—', '—']]
        );

        $this->newLine();
        $this->warn('Разбирает человек — сопоставление отказалось угадывать:');
        $this->table(['Курс', 'Имя в Zoom', 'Уверенность', 'Почему'], $needHuman ?: [['—', '—', '—', '—']]);

        $this->newLine();
        $this->info(sprintf('Итого: к заведению %d, человеку %d.', count($planned), count($needHuman)));

        if (! $this->option('apply')) {
            $this->comment('Сухой прогон — ничего не записано. Повторите с --apply.');

            return self::SUCCESS;
        }

        $inserted = 0;
        InsertOnlyGuard::around(function () use ($planned, &$inserted): void {
            DB::transaction(function () use ($planned, &$inserted): void {
                foreach ($planned as $p) {
                    $exists = WebinarParticipantLink::where('course_id', $p['course_id'])
                        ->where('zoom_name_key', $p['zoom_name_key'])
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    // `reason`/`candidate` живут только в отчёте; в БД идут поля
                    // модели. Источник различает точную и нечёткую связку, чтобы
                    // нечёткие можно было отревизовать или снять одним запросом,
                    // не трогая те, что совпали буква в букву.
                    WebinarParticipantLink::create([
                        'course_id' => $p['course_id'],
                        'user_id' => $p['user_id'],
                        'zoom_name' => $p['zoom_name'],
                        'zoom_name_key' => $p['zoom_name_key'],
                        'confidence' => $p['confidence'],
                        'source' => $p['confidence'] === 'fuzzy' ? 'auto_fuzzy' : 'auto_name',
                    ]);
                    $inserted++;
                }
            });
        });

        $this->info("Заведено связок: {$inserted}.");
        $this->comment('webinar_attendances не изменён: связки живут отдельной таблицей (InsertOnlyGuard).');

        return self::SUCCESS;
    }

    /** @return Collection<int, Course> */
    private function targetCourses()
    {
        $ids = array_filter(array_map('intval', (array) $this->option('course')));

        if ($ids !== []) {
            return Course::whereIn('id', $ids)->get();
        }

        $withAttendance = DB::table('webinar_attendances as wa')
            ->join('schedules as s', 's.id', '=', 'wa.schedule_id')
            ->whereNotNull('s.course_id')
            ->distinct()
            ->pluck('s.course_id');

        return Course::whereIn('id', $withAttendance)->get();
    }
}
