<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\VideoLinkNormalizer;
use App\Support\TranscriptParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class LessonController extends Controller
{
    public function sync(Request $request)
    {
        if ($unauthorized = $this->guard($request)) {
            return $unauthorized;
        }

        $courses = $request->validate([
            '*.id' => 'required|integer',
            '*.title' => 'required|string|max:255',
            // Группа курса (для курсов, разнесённых на 2 потока). Необязательна:
            // несплитнутый курс шлёт без неё → group_id = NULL (видно всем группам).
            '*.group_id' => 'nullable|integer|exists:groups,id',
            '*.videoLinks' => 'nullable|array',
            '*.rutubeLinks' => 'nullable|array',
            '*.lessonTopics' => 'nullable|array',
            '*.flashCards' => 'nullable|array',
        ]);

        foreach ($courses as $course) {
            $courseId = $course['id'];
            $title = $course['title'];
            $groupId = $course['group_id'] ?? null;

            $dates = array_unique(array_merge(
                array_keys($course['videoLinks'] ?? []),
                array_keys($course['lessonTopics'] ?? [])
            ));

            foreach ($dates as $date) {
                // Ключ включает group_id: иначе уроки двух групп на одну дату
                // одного курса затирали бы друг друга. Eloquent корректно
                // матчит group_id = NULL через IS NULL.
                Lesson::updateOrCreate(
                    ['course_id' => $courseId, 'group_id' => $groupId, 'lesson_date' => $date],
                    [
                        'title' => $title,
                        'video_url' => $course['videoLinks'][$date] ?? null,
                        'rutube_url' => $course['rutubeLinks'][$date] ?? null,
                        'topic' => $course['lessonTopics'][$date] ?? null,
                        'flash_cards' => $course['flashCards'][$date] ?? null,
                    ]
                );
            }
        }

        return response()->json(['status' => 'success', 'message' => 'Database synchronized']);
    }

    /**
     * Создание одиночного урока из n8n-сценария обработки записи Zoom.
     * Идемпотентно по (course_id, lesson_date): повторный прогон того же
     * исполнения (та же Zoom start_time) обновляет запись, а не плодит дубль.
     */
    public function storeFromZoom(Request $request, VideoLinkNormalizer $links)
    {
        if ($unauthorized = $this->guard($request)) {
            return $unauthorized;
        }

        $data = $request->validate([
            'course_id' => 'required|integer|exists:courses,id',
            'group_id' => 'nullable|integer|exists:groups,id',
            'title' => 'required|string|max:255',
            'lesson_date' => 'required|date',
            'block_number' => 'nullable|integer|min:1',
            'youtube_url' => 'nullable|string',
            'rutube_url' => 'nullable|string',
            'topic' => 'nullable|string',
            // Длительность в секундах. Сценарий отдаёт минуты — конвертируем ниже.
            'duration_seconds' => 'nullable|integer|min:0',
            'duration_minutes' => 'nullable|integer|min:0',
        ]);

        // Нормализуем ссылки: youtube → https://youtu.be/{id};
        // rutube → каноническая ссылка с сохранением приватного токена ?p=.
        $youtube = $links->youtube($data['youtube_url'] ?? null);
        $rutube = $links->rutube($data['rutube_url'] ?? null);

        // duration: принимаем либо секунды, либо минуты (сценарий шлёт минуты).
        $durationSeconds = $data['duration_seconds']
            ?? (isset($data['duration_minutes']) ? $data['duration_minutes'] * 60 : null);

        // lesson_date в модели кастится в date (время отбрасывается). Ищем через
        // whereDate, иначе сравнение сырой ISO-строки с сохранённым значением
        // (Eloquent пишет date-каст как 'Y-m-d 00:00:00') не совпадёт и урок
        // продублируется при повторном прогоне того же исполнения.
        $lessonDate = Carbon::parse($data['lesson_date'])->toDateString();
        $groupId = $data['group_id'] ?? null;

        // Ключ идемпотентности включает group_id (для курсов, разнесённых на 2
        // группы): where('group_id', null) Eloquent трактует как IS NULL.
        $lesson = Lesson::query()
            ->where('course_id', $data['course_id'])
            ->where('group_id', $groupId)
            ->whereDate('lesson_date', $lessonDate)
            ->first();

        $wasCreated = $lesson === null;
        $lesson ??= new Lesson([
            'course_id' => $data['course_id'],
            'group_id' => $groupId,
            'lesson_date' => $lessonDate,
        ]);

        $lesson->fill([
            'title' => $data['title'],
            'topic' => $data['topic'] ?? null,
            'block_number' => $data['block_number'] ?? 1,
            'youtube_url' => $youtube,
            'rutube_url' => $rutube,
            'duration_seconds' => $durationSeconds,
            'is_published' => true,
            'is_free' => false,
        ])->save();

        Log::info('Lesson from Zoom: урок сохранён', [
            'lesson_id' => $lesson->id,
            'course_id' => $lesson->course_id,
            'created' => $wasCreated,
        ]);

        return response()->json([
            'status' => 'success',
            'lesson_id' => $lesson->id,
            'created' => $wasCreated,
        ]);
    }

    /**
     * Приём расшифровки Deepgram из того же n8n-сценария обработки записи.
     *
     * Транскрипт с таймкодами — единственный источник границ для нарезки клипов
     * (`ClipSpanPlanner` читает только `lesson.transcript_file`). До появления
     * этой ручки Deepgram-ответ оседал в Google Drive и в субтитрах Rutube, а в
     * LMS не попадал: на проде transcript_file был пуст у всех 1666 уроков, и
     * «Нарезать лекцию» гарантированно давала пустой список спанов.
     *
     * Тело — сырой ответ Deepgram (или обёртка n8n): формат разбирает
     * TranscriptParser, он же кэширует по mtime, поэтому перезаливка сама
     * инвалидирует кэш плеера и лендинга.
     */
    public function storeTranscript(Request $request, Lesson $lesson)
    {
        if ($unauthorized = $this->guard($request)) {
            return $unauthorized;
        }

        $payload = $request->input('transcript', $request->all());

        if (! is_array($payload) || TranscriptParser::wordsFrom($payload) === []) {
            // Молча сохранить пустышку хуже, чем отказать: урок выглядел бы
            // готовым к нарезке, а спанов бы не было.
            Log::warning('Lesson transcript: в теле нет слов Deepgram', [
                'lesson_id' => $lesson->id,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'no Deepgram words in payload (results.channels[0].alternatives[0].words)',
            ], 422);
        }

        $path = 'transcripts/lesson-'.$lesson->id.'.json';
        Storage::disk('public')->put($path, json_encode($payload, JSON_UNESCAPED_UNICODE));

        $lesson->forceFill(['transcript_file' => $path])->save();

        $sentences = TranscriptParser::sentencesFromPublicFile($path);

        Log::info('Lesson transcript: сохранён', [
            'lesson_id' => $lesson->id,
            'sentences' => count($sentences),
        ]);

        return response()->json([
            'status' => 'success',
            'lesson_id' => $lesson->id,
            'transcript_file' => $path,
            'sentences' => count($sentences),
        ]);
    }

    /**
     * Fail-closed авторизация по общему секрету LESSON_SYNC_SECRET.
     * Возвращает 401-ответ при отказе либо null, если доступ разрешён.
     */
    private function guard(Request $request): ?JsonResponse
    {
        $secret = config('services.lesson_sync.secret');

        if (empty($secret) || ! hash_equals($secret, (string) $request->header('X-Secret-Key'))) {
            Log::warning('Lesson sync: неавторизованный доступ', [
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return null;
    }
}
