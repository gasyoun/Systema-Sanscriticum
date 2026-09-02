<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\User;
use App\Services\Access\TelegramLoginService;
use App\Services\AttributionService;
use App\Services\Membership\FreeTierLessonGranter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Самообслуживание «/кабинет <email>» в личке студент-бота (02-09-2026):
 * непривязанный студент одной командой создаёт себе личный кабинет
 * (Free-tier плейлисты, как при self-register H3643) и получает одноразовую
 * magic-ссылку входа. Пароль никогда не пересылается.
 *
 * Безопасность:
 *  - флаг features.telegram_cabinet_provision (default OFF), только вместе с
 *    telegram_cabinet_login;
 *  - только в личке (группы отсекает вебхук);
 *  - ≤1 СОЗДАНИЕ на telegram_id навсегда (cache-флаг); повторные вызовы
 *    возвращают ссылку входа, но аккаунтов не плодят;
 *  - существующий email НИКОГДА не перезаписывается и не привязывается по
 *    голому знанию email (это был бы угон) — вместо этого приглашение к
 *    обычному входу;
 *  - пароль random, наружу не уходит; вход только magic-link (purpose
 *    tg_login, TTL 15 мин, одноразовый).
 *
 * Класс только РЕШАЕТ и возвращает тексты; отправку в чат делает вебхук.
 */
class CabinetProvisionBotCommand
{
    public const COMMAND = '/кабинет';

    public function __construct(
        private TelegramLoginService $logins,
        private FreeTierLessonGranter $granter,
        private AttributionService $attribution,
    ) {}

    /** /кабинет (с аргументом-email или без). */
    public function isCommand(string $text): bool
    {
        $trimmed = mb_strtolower(trim($text));

        return $trimmed === self::COMMAND || str_starts_with($trimmed, self::COMMAND.' ');
    }

    /**
     * Указатель в группе: короткая фраза в личку, БЕЗ email-эха и без ссылок
     * входа (magic-link в группе увидели бы все участники).
     */
    public function groupPointerMessage(): string
    {
        return 'Личный кабинет создастся сам — напишите мне в личку командой '
            .'/кабинет ваш@email.com: '.config('app.telegram_bot_username', 'samskrtamru_bot');
    }

    /**
     * @return string HTML-сообщение для чата
     */
    public function replyForCommand(int $chatId, ?string $fromUsername, string $text): string
    {
        $argument = mb_strtolower(trim(preg_replace('/^\S+\s*/u', '', trim($text)) ?? ''));

        if ($argument !== '' && filter_var($argument, FILTER_VALIDATE_EMAIL) === false) {
            return 'Напишите команду и ваш email одним сообщением, например: /кабинет anna@example.com';
        }

        // Уже привязанный Telegram — кабинет уже есть, просто выдаём вход.
        $linked = User::where('telegram_id', $chatId)->first();
        if ($linked !== null) {
            if (! $this->logins->allowChat($chatId)) {
                return 'Слишком много попыток входа подряд. Подождите 15 минут и попробуйте ещё раз.';
            }

            return "У вас уже есть кабинет — просто войдите:\n\n"
                .$this->loginLinkMessage($this->logins->issueLoginLink($linked));
        }

        if ($argument === '') {
            return 'Создам вам личный кабинет. Напишите: /кабинет ваш@email.com — и пришлю одноразовую ссылку для входа.';
        }

        $email = User::normalizeEmail($argument);

        // Существующий email — не наш: не перезаписываем и не привязываем
        // (знание email ≠ право доступа). Мягкий отказ без утечки деталей.
        $existing = User::where('email', $email)->first();
        if ($existing !== null) {
            return 'Аккаунт с таким email уже существует. Войдите на сайте по своему паролю или напишите куратору — привязка чужого аккаунта по одному email не делается.';
        }

        // CAP: ≤1 создание на telegram_id за всё время (спам-щит).
        if (! Cache::add('tg-cabinet-provisioned:'.$chatId, true)) {
            return 'Кабинет по этому Telegram уже создан. Если ссылка входа потерялась — напишите /вход или /кабинет ещё раз.';
        }

        $user = $this->createUser($email, $chatId, $fromUsername);

        return "Намасте, {$user->name}! 🙏 Ваш личный кабинет создан — вход без пароля:\n\n"
            .$this->loginLinkMessage($this->logins->issueLoginLink($user))
            ."\n\n<i>Бесплатные плейлисты уже открыты внутри.</i>";
    }

    private function createUser(string $email, int $chatId, ?string $fromUsername): User
    {
        $local = explode('@', $email)[0] ?: 'Студент';

        $user = User::create([
            'email' => $email,
            'name' => $local,
            'password' => Hash::make(Str::random(12)),
            'telegram_id' => $chatId,
            'telegram_username' => User::normalizeTelegramUsername($fromUsername),
            'telegram_connected_at' => now(),
        ]);

        $this->attribution->applyToNewUser($user);
        $this->attribution->applySignupSource($user, 'telegram');
        $this->granter->grantSignupFor($user);

        return $user;
    }

    private function loginLinkMessage(string $link): string
    {
        return "🔑 <b>Вход в личный кабинет</b>\n\n"
            ."Нажмите на ссылку — войдёте без пароля:\n\n"
            ."<a href='{$link}'>Войти в личный кабинет</a>\n\n"
            .'<i>Ссылка одноразовая и действует 15 минут. Потом — напишите /вход.</i>';
    }
}
