<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\LessonAiJob;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Access\LessonGate;
use App\Services\Knowledge\LessonAiService;
use App\Support\ServerGuards\OllamaTunnelProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * ИИ-помощник на странице занятия (features.lesson_ai).
 *
 *   POST /c/{slug}/u/{lessonId}/ai          {task, text?, notes?, question?}
 *        → 202 {status: pending, token} | 200 {status: done, …} | 4xx/503 {status: error, error}
 *   GET  /c/{slug}/u/{lessonId}/ai/{token}  → {status: pending|done|error, …}
 *
 * Гейт — LessonGate::canWatch, тот же, что у стенограммы и плеера: нет доступа
 * к уроку — 404, как у GatedAssetController.
 */
final class LessonAiController extends Controller
{
    public function __construct(private readonly LessonAiService $service) {}

    public function start(Request $request, string $slug, int $lessonId): JsonResponse
    {
        abort_unless($this->service->enabledFor($request->user()), 404);
        /** @var User $user */
        $user = $request->user();
        $lesson = $this->resolveAccessible($user, $slug, $lessonId);

        $data = $request->validate([
            'task' => ['required', 'string', 'in:'.implode(',', LessonAiService::TASKS)],
            'text' => ['nullable', 'string', 'max:4000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'question' => ['nullable', 'string', 'max:500'],
        ]);
        $task = $data['task'];
        $input = array_filter([
            'text' => trim((string) ($data['text'] ?? '')),
            'notes' => trim((string) ($data['notes'] ?? '')),
            'question' => trim((string) ($data['question'] ?? '')),
        ], static fn (string $v): bool => $v !== '');

        $precheck = $this->precheck($task, $lesson, $input);
        if ($precheck !== null) {
            return $this->error($precheck, 422);
        }

        // Готовый пересказ занятия — сразу, без модели и без расхода лимита.
        if ($task === 'summary' && ($md = $this->service->cachedSummary($lesson)) !== null) {
            return response()->json(LessonAiJob::donePayload($this->service, $task, ['md' => $md]) + ['cached' => true]);
        }

        if (! OllamaTunnelProbe::withinServiceWindow()) {
            return $this->error('asleep', 503, ['hours' => (string) config('knowledge.tunnel_hours', '')]);
        }

        $limitKey = 'lesson-ai:'.$user->id.':'.now()->toDateString();
        $limit = max(1, (int) config('knowledge.lesson_ai.daily_limit', 30));
        if (RateLimiter::tooManyAttempts($limitKey, $limit)) {
            return $this->error('limit', 429);
        }
        RateLimiter::hit($limitKey, 86400);

        $token = Str::random(32);
        Cache::put(LessonAiJob::key($token), [
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'task' => $task,
            'status' => 'pending',
        ], LessonAiJob::TTL);

        LessonAiJob::dispatch($token, $lesson->id, $task, $input);

        // sync-очередь (локально, тесты) уже всё посчитала — отдаём сразу.
        $state = $this->publicState(Cache::get(LessonAiJob::key($token)));
        if ($state !== null && $state['status'] !== 'pending') {
            return response()->json($state);
        }

        return response()->json([
            'status' => 'pending',
            'token' => $token,
            'left' => max(0, $limit - RateLimiter::attempts($limitKey)),
        ], 202);
    }

    public function poll(Request $request, string $slug, int $lessonId, string $token): JsonResponse
    {
        abort_unless($this->service->enabledFor($request->user()), 404);
        /** @var User $user */
        $user = $request->user();
        $lesson = $this->resolveAccessible($user, $slug, $lessonId);

        $raw = Cache::get(LessonAiJob::key($token));
        // Чужой токен неотличим от истёкшего: 404 в обоих случаях.
        abort_unless(
            is_array($raw) && (int) $raw['user_id'] === (int) $user->id && (int) $raw['lesson_id'] === (int) $lesson->id,
            404,
        );

        return response()->json($this->publicState($raw));
    }

    /** @param  array{text?: string, notes?: string, question?: string}  $input */
    private function precheck(string $task, Lesson $lesson, array $input): ?string
    {
        $hasTranscript = $this->service->hasTranscript($lesson);

        return match ($task) {
            'summary', 'ask' => ! $hasTranscript ? 'no-transcript'
                : ($task === 'ask' && mb_strlen($input['question'] ?? '') < 5 ? 'short-question' : null),
            'quiz' => ! $hasTranscript && mb_strlen($input['notes'] ?? '') < 80 ? 'few-notes' : null,
            'outline' => mb_strlen($input['notes'] ?? '') < 40 ? 'few-notes' : null,
            'explain' => mb_strlen($input['text'] ?? '') < 3 ? 'no-selection' : null,
            default => 'bad-task',
        };
    }

    private function resolveAccessible(User $user, string $slug, int $lessonId): Lesson
    {
        $course = Course::resolveBySlugOrFail($slug);
        $lesson = Lesson::with('course')->where('course_id', $course->id)->findOrFail($lessonId);

        abort_unless(app(LessonGate::class)->canWatch($user, $lesson), 404);

        return $lesson;
    }

    /**
     * @param  mixed  $raw
     * @return array<string, mixed>|null
     */
    private function publicState($raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }
        unset($raw['user_id'], $raw['lesson_id']);

        return $raw;
    }

    /** @param  array<string, mixed>  $extra */
    private function error(string $code, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['status' => 'error', 'error' => $code] + $extra, $status);
    }
}
