<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\User;

/**
 * Единая подстановка плейсхолдеров {name}/{course}/{block}/{pay_link} в тексты
 * сообщений. Раньше жила прямо в {@see \App\Services\DebtorReminderDispatcher};
 * вынесена сюда, чтобы её переиспользовали и общая библиотека шаблонов
 * (MessageTemplate), и реактивация, и напоминания должникам — один источник
 * правды для набора плейсхолдеров и их значений. См. H221.
 */
final class MessagePlaceholders
{
    /** Список поддерживаемых плейсхолдеров — для подсказок в UI. */
    public const KEYS = ['{name}', '{course}', '{block}', '{pay_link}'];

    /**
     * Значения плейсхолдеров для пары (пользователь, курс, блок).
     *
     * @return array<string, string>
     */
    public static function forUser(User $user, ?Course $course = null, ?int $blockNumber = null): array
    {
        $slug = $course?->slug;

        return [
            '{name}' => $user->name ?: 'Друг',
            '{course}' => $course?->title ?? '',
            '{block}' => (string) ($blockNumber ?? ''),
            '{pay_link}' => $slug ? route('student.course', $slug) : url('/login'),
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
