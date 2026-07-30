<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Resources\UserResource;
use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Уведомления по онбордингу студентов в выделенный Telegram-чат:
 *  - «первый вход в кабинет» (мгновенно при первом логине студента);
 *  - еженедельная сводка по тем, у кого есть доступ, но кто ни разу не заходил.
 *
 * Получатель — config('services.telegram.onboarding_chat_id'). Если он не задан,
 * все методы — no-op (фича выключена). Отправляет основной бот
 * (config('services.telegram.bot_token')) через SendTelegramChatMessageJob —
 * тот же паттерн, что и у App\Services\CuratorNotifier.
 *
 * Placeholder / synthetic accounts (import `@no-email.com`, live-probe
 * `@example.invalid` / `@example.com`) still get a first-login ping so probes
 * stay visible, but the message is prefixed with 🧪 so ops does not treat them
 * as real students. See RESULTS_LOG «H1946» and User id 6858.
 */
class OnboardingNotifier
{
    /**
     * Email suffixes that mark non-student / probe accounts (not real mailboxes).
     * Do NOT include `@example.com` — Faker `safeEmail()` uses it in tests and
     * would empty the weekly digest. Probes must use `@example.invalid` (RFC
     * reserved, never used by Faker).
     */
    public const PLACEHOLDER_EMAIL_SUFFIXES = [
        '@no-email.com',
        '@example.invalid',
    ];

    public function firstLogin(User $user): void
    {
        $lines = [];
        if ($this->isPlaceholderEmail($user->email ?? null)) {
            $lines[] = '🧪 <b>SYNTHETIC / TEST</b> — не настоящий студент';
            $lines[] = '';
        }

        $lines[] = '🚀 <b>Первый вход в кабинет</b>';
        $lines[] = '';
        $lines[] = $this->studentLine($user);

        $courses = $this->courseTitlesFor($user);
        if ($courses !== '') {
            $lines[] = 'Курсы: <b>'.$courses.'</b>';
        }

        $lines[] = 'Когда: '.now()->format('d.m.Y H:i');
        $lines[] = $this->adminLink($user);

        $this->dispatchToOnboarding($this->join($lines));
    }

    public function isPlaceholderEmail(?string $email): bool
    {
        $email = strtolower(trim((string) $email));
        if ($email === '') {
            return false;
        }

        foreach (self::PLACEHOLDER_EMAIL_SUFFIXES as $suffix) {
            if (str_ends_with($email, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, User>  $sample  до ~15 не зашедших — для прозвона
     */
    public function notEnteredDigest(int $accessSent, int $notEntered, Collection $sample): void
    {
        $percent = $accessSent > 0 ? (int) round($notEntered / $accessSent * 100) : 0;

        $lines = [
            '📊 <b>Не зашли в кабинет</b>',
            '',
            'Доступ выслан (реальный email): <b>'.$accessSent.'</b>',
            'Из них ни разу не входили: <b>'.$notEntered.'</b> (<b>'.$percent.'%</b>)',
        ];

        if ($sample->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Кого можно подтолкнуть:';
            foreach ($sample as $u) {
                $lines[] = '• '.$this->studentLine($u);
            }
            if ($notEntered > $sample->count()) {
                $lines[] = '… и ещё '.($notEntered - $sample->count()).'.';
            }
        }

        $this->dispatchToOnboarding($this->join($lines));
    }

    // ==========================================================
    // Внутреннее
    // ==========================================================

    private function dispatchToOnboarding(string $text): void
    {
        $chatId = (string) (config('services.telegram.onboarding_chat_id') ?? '');
        if ($chatId === '') {
            return; // фича выключена — чат не настроен
        }

        SendTelegramChatMessageJob::dispatch($chatId, $text);
    }

    private function courseTitlesFor(User $user): string
    {
        $groupIds = $user->groups()->pluck('groups.id');
        if ($groupIds->isEmpty()) {
            return '';
        }

        $titles = Course::query()
            ->whereHas('groups', fn ($q) => $q->whereIn('groups.id', $groupIds))
            ->pluck('title')
            ->all();

        return $titles ? e(implode(', ', $titles)) : '';
    }

    /** @param  list<string>  $lines */
    private function join(array $lines): string
    {
        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    private function studentLine(User $user): string
    {
        $contacts = array_filter([
            $user->phone ?? null,
            $user->email ?? null,
            $user->telegram_id ? 'tg:'.$user->telegram_id : null,
        ]);

        $line = '<b>'.e((string) ($user->name ?? ('#'.$user->id))).'</b>';
        if ($contacts) {
            $line .= ' ('.e(implode(', ', $contacts)).')';
        }

        return $line;
    }

    private function adminLink(User $user): string
    {
        try {
            $url = UserResource::getUrl('edit', ['record' => $user->id]);
        } catch (\Throwable) {
            $url = url('/admin');
        }

        return "👉 <a href=\"{$url}\">Карточка студента</a>";
    }
}
