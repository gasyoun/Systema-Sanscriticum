<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Резолвер фактов для FAQ-суггестера (H247): по категории вопроса (A/B/C) тянет
 * данные из LMS (Schedule/Lesson группы студента) и собирает ГОТОВЫЙ ЧЕРНОВИК
 * ответа из строкового шаблона — БЕЗ единого LLM-вызова. Возвращает null, когда
 * фактов нет (нет ближайшего занятия / ссылки / опубликованной записи) — тогда
 * черновик не создаётся (не показываем куратору пустышку).
 */
class SupportAnswerFactResolver
{
    /**
     * @return array{draft: string, facts: array<string, mixed>, confidence: float}|null
     */
    public function resolve(string $category, User $user): ?array
    {
        return match ($category) {
            SupportAnswerSuggestion::CATEGORY_ZOOM => $this->resolveZoom($user),
            SupportAnswerSuggestion::CATEGORY_SCHEDULE => $this->resolveSchedule($user),
            SupportAnswerSuggestion::CATEGORY_RECORDING => $this->resolveRecording($user),
            default => null,
        };
    }

    /**
     * D (гость, H1198) — публичные тарифы видимых активных курсов, БЕЗ
     * персонализации (`Tariff::calculateFinalPriceForUser` требует `User` — у
     * анонимного посетителя его нет). Показывает «от» самого дешёвого активного
     * тарифа курса — лояльность/скидки/рассрочка уточняются у куратора. Единственная
     * категория, доступная гостю: A/B/C/E/F завязаны на личное зачисление студента
     * (группа/расписание/материалы), у гостя таких фактов нет и быть не может.
     *
     * @return array{draft: string, facts: array<string, mixed>, confidence: float}|null
     */
    public function resolvePublicPricing(): ?array
    {
        $courses = Course::query()
            ->where('is_visible', true)
            ->where('is_active', true)
            ->with(['tariffs' => fn ($q) => $q->where('is_active', true)->orderBy('price')])
            ->get()
            ->filter(fn (Course $course): bool => $course->tariffs->isNotEmpty())
            ->values();

        if ($courses->isEmpty()) {
            return null;
        }

        $lines = $courses->map(function (Course $course): string {
            $cheapest = $course->tariffs->first();
            $price = number_format((float) $cheapest->price, 0, ',', ' ');

            return "· «{$course->title}» — от {$price} ₽";
        })->implode("\n");

        return [
            'draft' => "Актуальные тарифы курсов (точная цена с учётом скидок/рассрочки — уточните у куратора):\n{$lines}",
            'facts' => [
                'type' => 'public_pricing',
                'courses' => $courses->map(fn (Course $course): array => [
                    'course' => $course->title,
                    'from_price' => (float) $course->tariffs->first()->price,
                ])->all(),
            ],
            'confidence' => 0.5,
        ];
    }

    /** A — ссылка + время ближайшего занятия группы студента. */
    private function resolveZoom(User $user): ?array
    {
        $class = $this->nextClass($user);
        if ($class === null || empty($class->link)) {
            return null;
        }

        $title = trim((string) $class->title) !== '' ? "«{$class->title}» " : '';
        $draft = "Ближайшее занятие {$title}— {$this->formatWhen($class)} (МСК).\n"
            ."Ссылка для подключения: {$class->link}";

        return [
            'draft' => $draft,
            'facts' => [
                'type' => 'zoom',
                'schedule_id' => $class->id,
                'starts_at' => optional($class->start)->toIso8601String(),
                'title' => $class->title,
                'link' => $class->link,
            ],
            'confidence' => 0.9,
        ];
    }

    /** C — ближайшие занятия группы студента. */
    private function resolveSchedule(User $user): ?array
    {
        $classes = $this->upcomingClasses($user, 3);
        if ($classes->isEmpty()) {
            return null;
        }

        $lines = $classes->map(function (Schedule $class): string {
            $title = trim((string) $class->title) !== '' ? " — {$class->title}" : '';

            return "· {$this->formatWhen($class)}{$title}";
        })->implode("\n");

        return [
            'draft' => "Ближайшие занятия вашей группы (время МСК):\n{$lines}",
            'facts' => [
                'type' => 'schedule',
                'classes' => $classes->map(fn (Schedule $c): array => [
                    'schedule_id' => $c->id,
                    'starts_at' => optional($c->start)->toIso8601String(),
                    'title' => $c->title,
                ])->all(),
            ],
            'confidence' => 0.9,
        ];
    }

    /** B — ссылка на запись последнего опубликованного урока группы студента. */
    private function resolveRecording(User $user): ?array
    {
        $lesson = $this->latestRecordedLesson($user);
        if ($lesson === null) {
            return null;
        }

        $url = $lesson->youtube_url ?: ($lesson->rutube_url ?: $lesson->video_url);
        $title = trim((string) $lesson->title) !== '' ? "«{$lesson->title}» " : '';
        $draft = filled($url)
            ? "Запись урока {$title}доступна: {$url}"
            : "Запись урока {$title}доступна в личном кабинете, раздел «Уроки».";

        return [
            'draft' => $draft,
            'facts' => [
                'type' => 'recording',
                'lesson_id' => $lesson->id,
                'title' => $lesson->title,
                'url' => $url ?: null,
            ],
            'confidence' => 0.85,
        ];
    }

    private function nextClass(User $user): ?Schedule
    {
        $groupIds = $user->activeGroups->pluck('id')->all();
        if ($groupIds === []) {
            return null;
        }

        return Schedule::query()
            ->whereIn('group_id', $groupIds)
            ->whereNotNull('start')
            ->where('start', '>=', now())
            ->orderBy('start')
            ->first();
    }

    /** @return Collection<int, Schedule> */
    private function upcomingClasses(User $user, int $limit): Collection
    {
        $groupIds = $user->activeGroups->pluck('id')->all();
        if ($groupIds === []) {
            return collect();
        }

        return Schedule::query()
            ->whereIn('group_id', $groupIds)
            ->whereNotNull('start')
            ->where('start', '>=', now())
            ->orderBy('start')
            ->limit($limit)
            ->get();
    }

    private function latestRecordedLesson(User $user): ?Lesson
    {
        return Lesson::query()
            ->forUserGroups($user)
            ->where('is_published', true)
            ->withVideo()
            ->orderByDesc('lesson_date')
            ->orderByDesc('sort_order')
            ->first();
    }

    private function formatWhen(Schedule $class): string
    {
        return optional($class->start)->format('d.m.Y H:i') ?? '';
    }
}
