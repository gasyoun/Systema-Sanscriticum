<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Services\Membership\ClubEntitlement;
use App\Services\Membership\RecordingAccessPolicy;
use App\Support\KinescopePilot;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * H4396 — серверные ворота ВИДЕОПЕЙЛОДА записи (census
 * PAYWALL_CENSUS_2026-09-08 §A3: «гейт на странице, не на видео»).
 *
 * Дыра: доступ к записи проверялся только на СТРАНИЦЕ урока, а сама
 * unlisted-ссылка YouTube/RuTube лежала в HTML готовой строкой — один
 * оплаченный месяц экспонировал ID всего бэк-каталога навсегда (unlisted
 * на стороне YouTube не отзываем; зеркала типа складчин собирают ID из
 * HTML). Теперь страница вообще не несёт сырых ID: плеер грузит
 * /c/{slug}/u/{id}/video/{player}, а этот контроллер на КАЖДУЮ загрузку
 * прогоняет ту же цепочку, что и плеер (грант > клуб > группа, потом
 * оплата, потом H3916-членство), и только тогда отдаёт 302 на embed.
 *
 * Ноль изъятия бесплатных возможностей: is_free / is_preview (публичный
 * «пример урока» — единственная точка правды ShopController::preview)
 * отдаются всем, включая гостя; вывод на главную (shownOnMain = is_free)
 * остаётся прямой раздачей и этими воротами не трогается.
 *
 * Режим записи: enforces RecordingAccessPolicy (флаги
 * membership_recording_*; при OFF политика всегда пускает — прод-инертно,
 * staged rollout H2744/H3916 не меняется).
 */
class RecordingGateController extends Controller
{
    private const PLAYERS = ['youtube', 'rutube', 'kinescope', 'video'];

    public function show(Request $request, string $slug, int $lessonId, string $player): Response
    {
        if (! in_array($player, self::PLAYERS, true)) {
            abort(404);
        }

        $course = Course::resolveBySlugOrFail($slug);
        $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);
        $user = $request->user();

        // Бесплатные поверхности (free/preview) — гостю включительно.
        $public = (bool) $lesson->is_free || (bool) $lesson->is_preview;

        if (! $public) {
            if ($user === null) {
                abort(404);
            }

            // Та же цепочка оснований, что у плеера StudentController::showLesson:
            // грант на урок > клубное покрытие > видимость по группе.
            $hasLessonGrant = LessonAccessGrant::userCanWatch($user, $lesson);
            $club = app(ClubEntitlement::class);
            $clubCovers = $club->coversCourse($user, $course);
            $clubLesson = $club->coversLesson($user, $course, $lesson);

            if (! $hasLessonGrant && ! $clubCovers && ! $clubLesson && ! $lesson->isVisibleToGroupsOf($user)) {
                abort(404);
            }

            // Оплата: те же ключи, что у страницы (H4396 expiry-предикат
            // внутри общего getUserUnlockedTariffs).
            $unlocked = StudentController::getUserUnlockedTariffs($user->id, $course->slug);

            if (! $hasLessonGrant && ! $clubLesson && ! $lesson->isUnlockedBy($unlocked)) {
                abort(404);
            }

            // H3916 entitlement: запись дополнительно гейтится членством.
            // Решение пишется в MembershipAccessVerdict — телеметрия rollout'а.
            $recording = app(RecordingAccessPolicy::class)->decide(
                $user,
                $course,
                $lesson,
                $hasLessonGrant,
                'web_recording_gate',
            );

            if (! $recording->allowed) {
                abort(404);
            }
        }

        $target = $this->embedTarget($lesson, $course->id ?? null, $player);

        abort_if($target === null, 404);

        // private, no-store: общие кэши не имеют права запоминать
        // авторизованную раздачу.
        return redirect()->away($target, 302, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Целевой embed-URL по подсказке плеера с фолбэком на приоритет урока
     * (kinescope > rutube > youtube > video — как в student/lesson.blade.php).
     */
    private function embedTarget(Lesson $lesson, ?int $courseId, string $player): ?string
    {
        foreach ($this->playerOrder($player) as $candidate) {
            $target = match ($candidate) {
                'kinescope' => KinescopePilot::embedForLesson($lesson, $courseId),
                'youtube' => $this->youtubeEmbed($lesson->youtube_url),
                'rutube' => $this->rutubeEmbed($lesson->rutube_url),
                'video' => $this->genericEmbed($lesson->video_url),
                default => null,
            };

            if ($target !== null) {
                return $target;
            }
        }

        return null;
    }

    /** @return list<string> запрошенный плеер первым, затем приоритет урока */
    private function playerOrder(string $player): array
    {
        return array_values(array_unique([$player, 'kinescope', 'rutube', 'youtube', 'video']));
    }

    private function youtubeEmbed(?string $url): ?string
    {
        $id = StudentController::parseVideoId($url, 'youtube');

        return $id === null ? null : 'https://www.youtube.com/embed/'.$id.'?enablejsapi=1&rel=0';
    }

    private function rutubeEmbed(?string $url): ?string
    {
        $id = StudentController::parseVideoId($url, 'rutube');

        return $id === null ? null : 'https://rutube.ru/play/embed/'.$id;
    }

    /** Generic fallback (video_url) — только готовые http(s)-embed, наружу наружу. */
    private function genericEmbed(?string $url): ?string
    {
        if ($url === null || ! Str::startsWith($url, ['https://', 'http://'])) {
            return null;
        }

        return $url;
    }
}
