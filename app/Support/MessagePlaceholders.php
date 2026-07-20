<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\User;
use App\Services\DebtorReminderDispatcher;

/**
 * Единая подстановка плейсхолдеров {name}/{course}/{block}/{pay_link} в тексты
 * сообщений. Раньше жила прямо в {@see DebtorReminderDispatcher};
 * вынесена сюда, чтобы её переиспользовали и общая библиотека шаблонов
 * (MessageTemplate), и реактивация, и напоминания должникам — один источник
 * правды для набора плейсхолдеров и их значений. См. H221.
 */
final class MessagePlaceholders
{
    /** Список поддерживаемых плейсхолдеров — для подсказок в UI. */
    public const KEYS = ['{name}', '{course}', '{block}', '{pay_link}', '{paid_until}', '{deadline}'];

    /**
     * Значения плейсхолдеров для пары (пользователь, курс, блок).
     *
     * $paidUntilLabel/$deadlineLabel — готовые фрагменты-предложения (с
     * ведущим пробелом и точкой в конце, например " Предыдущая оплата
     * покрывала до блока №5 (до 12.08.2026)."), а не голые значения — так их
     * можно просто конкатенировать в шаблон без риска сломать грамматику,
     * когда данных нет (тогда это пустая строка). Источник: {@see
     * \App\Services\StudentDebtsService::paidUntilForUser} для paid_until;
     * дедлайн = старт следующего/текущего непокрытого блока, 00:00 по Москве
     * (приложение работает в Europe/Moscow — см. config('app.timezone')).
     *
     * @return array<string, string>
     */
    public static function forUser(User $user, ?Course $course = null, ?int $blockNumber = null, ?string $paidUntilLabel = null, ?string $deadlineLabel = null): array
    {
        $slug = $course?->slug;

        return [
            '{name}' => $user->name ?: 'Друг',
            '{course}' => $course?->title ?? '',
            '{block}' => (string) ($blockNumber ?? ''),
            '{pay_link}' => $slug ? route('student.course', $slug) : url('/login'),
            '{paid_until}' => $paidUntilLabel ?? '',
            '{deadline}' => $deadlineLabel ?? '',
        ];
    }

    /**
     * Подставить значения в шаблон. Плейсхолдеры без значения остаются как есть.
     *
     * @param  array<string, string>  $replacements
     */
    public static function render(string $template, array $replacements): string
    {
        return strtr($template, $replacements);
    }
}
