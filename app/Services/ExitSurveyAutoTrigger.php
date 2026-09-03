<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * H3915 — Exit-опрос на авто-триггер «завершение курса» (джей-дик куратора,
 * docs/CURATOR_NASTYA_GORBACHENKO_JD_AND_AUTOMATION_02-09-2026 §3).
 *
 * По событию завершения (Course.is_completed false→true) собираем когорту
 * этого курса «спросил цену → оплаты нет» и уведомляем кураторский чат
 * ГОТОВЫМИ черновиками для личной отправки каждому (правило
 * ACQUISITION_SURVEY_INSTRUMENTS_2026H2: отправляет куратор лично каждому,
 * НЕ рассылкой; один контакт — потом тишина; без вопроса «почему не
 * оплатили»). Система студентам сама НЕ пишет — fence хандоффа.
 *
 * Включается флагом features.exit_survey_auto_trigger (default OFF). Дедуп —
 * courses.exit_survey_triggered_at; ручной/догоняющий прогон — команда
 * surveys:exit-survey-completed.
 */
class ExitSurveyAutoTrigger
{
    /** Публичная анкета из config/surveys.php («спросил цену — и мы пропали»). */
    public const SURVEY_SLUG = 'exit-price';

    /** Правило инструмента: контакт через ≥ 14 дней после последнего касания. */
    public const MIN_DAYS_SINCE_TOUCH = 14;

    public function __construct(private readonly CuratorNotifier $notifier) {}

    /** Вызывается из Course::updated при переходе is_completed в true. */
    public function handleCompleted(Course $course): void
    {
        if (! config('features.exit_survey_auto_trigger', false)) {
            return; // флаг OFF — полностью прод-инертно
        }

        $this->run($course);
    }

    /**
     * Разобрать курс: когорта → уведомление куратору → штамп. Возвращает
     * когорту (для команды/тестов). Повторный прогон молчит по штампу.
     *
     * @return Collection<int, User>
     */
    public function run(Course $course, bool $force = false): Collection
    {
        if (! $force && $course->exit_survey_triggered_at !== null) {
            return collect();
        }

        $users = $this->cohortFor($course);

        // Штамп ставим всегда (даже при пустой когорте — курс разобран).
        $course->forceFill(['exit_survey_triggered_at' => now()])->save();

        if ($users->isNotEmpty()) {
            $this->notifier->exitSurveyBatchReady($course, $users, $this->blocks($users));
        }

        return $users;
    }

    /**
     * Когорта «спросил цену → оплаты нет» по курсу: был реальный заказ
     * (conversion.excluded_tariffs и conditional-доступы не считаются), оплаты
     * по курсу нет и не было, последнее касание ≥ 14 дней назад.
     *
     * @return Collection<int, User>
     */
    public function cohortFor(Course $course): Collection
    {
        $lastAsks = Payment::query()
            ->where('course_id', $course->id)
            ->where('is_conditional', false)
            ->whereNotIn('status', Payment::PAID_STATUSES)
            ->whereNotIn('tariff', $this->excludedTariffs())
            ->whereNotNull('user_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(created_at) as last_ask_at')
            ->pluck('last_ask_at', 'user_id');

        if ($lastAsks->isEmpty()) {
            return collect();
        }

        $paidUserIds = Payment::query()
            ->where('course_id', $course->id)
            ->whereIn('status', Payment::PAID_STATUSES)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->all();

        $cutoff = today()->subDays(self::MIN_DAYS_SINCE_TOUCH)->endOfDay();

        $cohortIds = $lastAsks
            ->filter(fn (mixed $lastAsk, int $userId): bool => ! in_array($userId, $paidUserIds, true)
                && Carbon::parse($lastAsk)->lte($cutoff))
            ->keys();

        if ($cohortIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $cohortIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * Блок черновика в кураторском сообщении: контакты + готовый текст для
     * копирования и личной отправки.
     */
    public function blockFor(User $user): string
    {
        $contacts = array_filter([
            $user->telegram_username ? '@'.$user->telegram_username : null,
            $user->phone,
            $user->email,
        ]);

        $line = '<b>'.e((string) ($user->name ?? ('#'.$user->id))).'</b>';
        if ($contacts !== []) {
            $line .= ' ('.e(implode(', ', $contacts)).')';
        }

        return $line."\n".$this->draftFor($user);
    }

    /**
     * Готовый текст сообщения студенту — канон приглашения
     * ACQUISITION_SURVEY_INSTRUMENTS_2026H2 («анкета 1 — Exit»), без вопроса
     * «почему не оплатили», без набора/скидок/счётчиков, обещание тишины.
     */
    public function draftFor(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));
        $greeting = $name !== '' ? 'Здравствуйте, '.$name.'!' : 'Здравствуйте!';

        return $greeting.' Вы писали нам про курсы санскрита — спасибо, что написали тогда. '
            .'Хочу задать несколько коротких вопросов, чтобы понять, что у нас устроено неудобно. '
            .'Это займёт две минуты. За ответы — небольшая благодарность на выбор: прана на 500 ₽ '
            .'или бесплатное вводное занятие. Больше по этому поводу писать не буду — обещаю 🙏 '
            .url('/anketa/'.self::SURVEY_SLUG);
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<string>
     */
    private function blocks(Collection $users): array
    {
        return $users
            ->map(fn (User $user) => $this->blockFor($user))
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function excludedTariffs(): array
    {
        $excluded = (array) config('conversion.excluded_tariffs', ['Расход', 'salary_payout', 'deposit', 'trial']);

        return array_values(array_map(fn ($t): string => (string) $t, $excluded));
    }
}
