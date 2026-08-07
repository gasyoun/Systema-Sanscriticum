<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\User;
use App\Services\DebtorReminderDispatcher;

/**
 * Единая подстановка плейсхолдеров {name}/{course}/{block}/{pay_link}/{email}
 * в тексты сообщений. Раньше жила прямо в {@see DebtorReminderDispatcher};
 * вынесена сюда, чтобы её переиспользовали и общая библиотека шаблонов
 * (MessageTemplate), и реактивация, и напоминания должникам — один источник
 * правды для набора плейсхолдеров и их значений. См. H221 · H2339.
 *
 * {login_link} намеренно НЕ авто-подставляется: magic-link одноразовый и
 * выдаётся куратором из «Разблокировать»; плейсхолдер остаётся в тексте
 * для вставки вручную (см. MANUAL_CURATOR_MAGIC_LOGIN_LINK_RU §7).
 */
final class MessagePlaceholders
{
    /** Список поддерживаемых плейсхолдеров — для подсказок в UI. */
    public const KEYS = ['{name}', '{course}', '{block}', '{pay_link}', '{email}', '{paid_until}', '{deadline}'];

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
     * {email} — нормализованный email карточки; placeholder-адреса
     *
     * (*@no-email.com) схлопываются в пустую строку, чтобы шаблон не
     * обещал вход по почте, которой нет.
     *
     * @return array<string, string>
     */
    public static function forUser(User $user, ?Course $course = null, ?int $blockNumber = null, ?string $paidUntilLabel = null, ?string $deadlineLabel = null): array
    {
        $slug = $course?->slug;
        $email = (string) ($user->email ?? '');
        if ($email !== '' && str_ends_with(mb_strtolower($email), '@no-email.com')) {
            $email = '';
        }

        return [
            '{name}' => $user->name ?: 'Друг',
            '{course}' => $course?->title ?? '',
            '{block}' => (string) ($blockNumber ?? ''),
            '{pay_link}' => $slug ? route('student.course', $slug) : url('/login'),
            '{email}' => $email,
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
