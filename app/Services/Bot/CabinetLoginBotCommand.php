<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\User;
use App\Services\Access\TelegramLoginService;
use App\Support\Roles;

/**
 * «Telegram-вход» в кабинет со стороны студент-бота (CABINET_ADOPTION_ROADMAP
 * P2, 28-08-2026). Два сценария:
 *
 *  1. Уже привязанный студент пишет /start или /вход → одноразовая ссылка входа
 *     в тот же чат (владение Telegram = фактор подлинности).
 *  2. (отдельный флаг, @DECIDE владельца) Непривязанный студент присылает email
 *     заказа → матч по нормализованному email СРЕДИ ОПЛАЧИВАВШИХ (кабинетная
 *     аудитория), staff (admin/super_admin) исключён; привязываем telegram_id и
 *     выдаём ту же ссылку. Размен «знает email = получит доступ» — осознанный,
 *     сильнее разрешённой «самопроверки входа», поэтому за отдельным флагом.
 *
 * Класс только РЕШАЕТ и возвращает тексты; отправку в чат делает вебхук (единый
 * sendMessage с TelegramFormatter-фолбэком). Email наружу не эхом — ответ не
 * различает «нет такого адреса / не платил / staff».
 */
class CabinetLoginBotCommand
{
    public const LOGIN_COMMANDS = ['/login', '/вход'];

    public function __construct(private TelegramLoginService $logins) {}

    /** Мастер-флаг «Telegram-входа» (features.telegram_cabinet_login). */
    public function isEnabled(): bool
    {
        return $this->logins->isEnabled();
    }

    /** Под-флаг email-привязки (только вместе с мастером). */
    public function isEmailLinkEnabled(): bool
    {
        return $this->logins->isEmailLinkEnabled();
    }

    /** /login, /вход (регистр не важен, с аргументом и без). */
    public function isLoginCommand(string $text): bool
    {
        $trimmed = mb_strtolower(trim($text));

        foreach (self::LOGIN_COMMANDS as $cmd) {
            if ($trimmed === $cmd || str_starts_with($trimmed, $cmd.' ')) {
                return true;
            }
        }

        return false;
    }

    /** Похоже ли сообщение на email (сценарий 2). */
    public function looksLikeEmail(string $text): bool
    {
        return filter_var(trim($text), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Сценарий 1: ссылка входа для уже привязанного студента.
     *
     * @return string HTML-сообщение для чата (ссылка внутри)
     */
    public function replyForLinkedUser(User $user): string
    {
        if (! $this->logins->allowChat((int) $user->telegram_id)) {
            return 'Слишком много попыток входа подряд. Подождите 15 минут и напишите /вход ещё раз.';
        }

        return $this->loginLinkMessage($this->logins->issueLoginLink($user));
    }

    /**
     * Сценарий 2: матч по email заказа для непривязанного автора чата.
     * При успехе привязывает telegram_id (как deep-link привязка из кабинета)
     * и выдаёт ссылку входа.
     *
     * @return string HTML-сообщение для чата
     */
    public function replyForEmail(int $chatId, ?string $fromUsername, string $emailText): string
    {
        if (! $this->logins->allowChat($chatId)) {
            return 'Слишком много попыток входа подряд. Подождите 15 минут и попробуйте ещё раз.';
        }

        $email = User::normalizeEmail($emailText);
        $candidate = User::where('email', $email)->first();

        $allowed = $candidate !== null
            && ! $this->isStaff($candidate)
            && $candidate->payments()->where('status', 'paid')->exists();

        if (! $allowed) {
            return 'Не нашёл оплат по этому email. Проверьте адрес — тот, что вы указывали при оплате курса, — или напишите куратору.';
        }

        // Привязываем тем же набором полей, что и deep-link привязка из кабинета.
        $candidate->update([
            'telegram_id' => $chatId,
            'telegram_username' => User::normalizeTelegramUsername($fromUsername),
            'telegram_auth_token' => null,
            'telegram_connected_at' => now(),
        ]);

        return "Намасте, {$candidate->name}! 🙏 Ваш аккаунт привязан к Telegram.\n\n"
            .$this->loginLinkMessage($this->logins->issueLoginLink($candidate));
    }

    /** Текст «пришлите email» для непривязанного автора, когда сценарий 2 включён. */
    public function askForEmailMessage(): string
    {
        return "Ваш Telegram пока не привязан к аккаунту Академии.\n\n"
            .'Пришлите email, указанный при оплате курса, — найду вас и пришлю одноразовую ссылку для входа без пароля.';
    }

    private function isStaff(User $user): bool
    {
        return $user->isSuperAdmin() || $user->role === Roles::ADMIN || $user->role === Roles::MANAGER;
    }

    private function loginLinkMessage(string $link): string
    {
        return "🔑 <b>Вход в личный кабинет</b>\n\n"
            ."Нажмите на ссылку — войдёте без пароля:\n\n"
            ."<a href='{$link}'>Войти в личный кабинет</a>\n\n"
            .'<i>Ссылка одноразовая и действует 15 минут. Если не сработала — напишите /вход ещё раз.</i>';
    }
}
