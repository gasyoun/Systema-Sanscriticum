<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\User;

/**
 * Чеклист первых шагов студента в кабинете (P0-онбординг).
 *
 * Все шаги определяются по уже существующим сигналам — без отдельной схемы.
 * Текст-основа — docs/onboarding-student.md. Шаг «домашнее задание» считается
 * выполненным, если у курсов студента вообще нет ДЗ (иначе карточка висела бы
 * вечно у тех, кому нечего сдавать).
 */
class OnboardingChecklist
{
    /**
     * Утверждённый чек-лист привязки мессенджера (H6186, Hermes W1 роль 19).
     *
     * Единственный источник шагов для черновиков подготовки привязки
     * («черновик строго из утверждённого чек-листа привязки» — golden-контракт
     * role_19_onboarding.json). Текст — только из утверждённых источников,
     * нового копирайта здесь нет:
     *
     *  - шаг 1 — label/hint шага `link_messenger` метода for() выше
     *    (текст-основа docs/onboarding-student.md: «Привяжите Telegram, чтобы
     *    получать уведомления и писать в поддержку»);
     *  - шаг 2 — утверждённая инструкция страницы привязки
     *    resources/views/student/messenger-connect.blade.php
     *    (GET /telegram/connect — «инструкция без мутаций», H3313).
     *
     * Меняется текст шагов ⇒ тот же PR обновляет перечисленные источники.
     *
     * @return list<array{key: string, label: string, hint: string}>
     */
    public static function bindingSteps(): array
    {
        return [
            [
                'key' => 'open_connect_page',
                'label' => 'Привяжите Telegram или VK',
                'hint' => 'Чтобы получать уведомления и писать в поддержку.',
            ],
            [
                'key' => 'press_connect',
                'label' => 'Открыть Telegram и привязать',
                'hint' => 'Кнопка откроет мессенджер в новой вкладке и безопасно свяжет его с вашим аккаунтом.',
            ],
        ];
    }

    /**
     * @return array{steps: list<array{key: string, label: string, hint: string, icon: string, done: bool, url: ?string}>, completed: int, total: int, allComplete: bool}
     */
    public static function for(User $user): array
    {
        $courseIds = Course::query()
            ->where('is_active', true)
            ->whereHas('groups', fn ($q) => $q->whereIn('groups.id', $user->groups->pluck('id')))
            ->pluck('id');

        $primaryCourse = Course::query()
            ->whereIn('id', $courseIds)
            ->orderBy('id')
            ->first(['id', 'slug']);

        $courseUrl = $primaryCourse
            ? route('student.course', $primaryCourse->slug)
            : null;

        $hasHomeworkLessons = Lesson::query()
            ->whereIn('course_id', $courseIds)
            ->where('homework_enabled', true)
            ->exists();
        $submittedHomework = $user->homeworkSubmissions()
            ->where('status', '!=', HomeworkSubmission::STATUS_DRAFT)
            ->exists();

        $steps = [
            [
                'key' => 'open_lesson',
                'label' => 'Откройте первый урок',
                'hint' => 'Начните с любого урока вашего курса.',
                'icon' => 'fa-play-circle',
                'done' => $user->lessonViews()->exists(),
                'url' => $courseUrl,
            ],
            [
                'key' => 'complete_lesson',
                'label' => 'Отметьте урок пройденным',
                'hint' => 'Кнопка «Пройдено» внизу урока — так виден ваш прогресс.',
                'icon' => 'fa-circle-check',
                'done' => $user->completedLessons()->exists(),
                'url' => $courseUrl,
            ],
            [
                'key' => 'homework',
                'label' => 'Сдайте домашнее задание',
                'hint' => 'Даже черновик — потом дополните по комментариям куратора.',
                'icon' => 'fa-pen-to-square',
                'done' => ! $hasHomeworkLessons || $submittedHomework,
                'url' => $courseUrl,
            ],
            [
                'key' => 'link_messenger',
                'label' => 'Привяжите Telegram или VK',
                'hint' => 'Чтобы получать уведомления и писать в поддержку.',
                'icon' => 'fa-paper-plane',
                'done' => filled($user->telegram_id) || filled($user->vk_id),
                'url' => route('telegram.connect'),
            ],
        ];

        $completed = count(array_filter($steps, fn (array $s): bool => $s['done']));
        $total = count($steps);

        return [
            'steps' => $steps,
            'completed' => $completed,
            'total' => $total,
            'allComplete' => $completed === $total,
        ];
    }
}
