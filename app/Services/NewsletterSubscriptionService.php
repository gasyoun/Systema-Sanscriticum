<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\NewsletterMagnetsMail;
use App\Models\Lead;
use App\Models\MagicLinkToken;
use App\Models\SubscriberMagnet;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Подписка не-студента на рассылку (H324): email → облегчённый кабинетный User
 * (беспарольный) + одноразовая magic-ссылка в письме + «полка подписчика».
 *
 * Ортогонально платному ядру: НЕ трогает группы/оплаты/тарифы. Пишем Lead-строку
 * ради CRM/UTM-атрибуции (лид→пользователь→оплата), как обычная заявка.
 */
class NewsletterSubscriptionService
{
    /**
     * @param  array<string,mixed>  $meta  utm/referrer/ip/user_agent/promo-consent
     * @return array{user: User, magic_url: string, created: bool}
     */
    public function subscribe(string $email, array $meta = []): array
    {
        $normalized = User::normalizeEmail($email) ?? '';

        [$user, $created] = $this->findOrCreateUser($normalized);

        // Отмечаем подписку (идемпотентно — повторная подписка просто обновляет дату).
        $user->forceFill(['newsletter_subscribed_at' => now()])->save();

        $this->recordLead($user, $normalized, $meta);

        // Одноразовая magic-ссылка на вход в кабинет — click = подтверждение подписки
        // (double-opt-in одним письмом; согласие уже дано явным чекбоксом).
        $plaintext = MagicLinkToken::issueFor($user, 'newsletter');
        $magicUrl = URL::route('newsletter.magic', ['token' => $plaintext]);

        Mail::to($user->email)->queue(new NewsletterMagnetsMail(
            userName: $user->name,
            magicUrl: $magicUrl,
            magnets: SubscriberMagnet::active()->get(),
        ));

        return ['user' => $user, 'magic_url' => $magicUrl, 'created' => $created];
    }

    /**
     * @return array{0: User, 1: bool} [user, wasCreated]
     */
    private function findOrCreateUser(string $normalizedEmail): array
    {
        $existing = User::where('email', $normalizedEmail)->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        // Беспарольный аккаунт: случайный пароль-заглушка (вход только по magic-link
        // или по восстановлению пароля). Тот же путь создания, что у соц-входа —
        // касты/обсерверы срабатывают одинаково.
        $user = User::create([
            'name' => 'Подписчик',
            'email' => $normalizedEmail,
            'password' => Hash::make(Str::random(40)),
            'wants_email_announcements' => true,
        ]);

        // A1-атрибуция только для реально новых аккаунтов (как в SocialAuthService).
        app(AttributionService::class)->applyToNewUser($user);

        return [$user, true];
    }

    /**
     * Пишет Lead-строку подписки для CRM/UTM. Дедуп по email: повторная подписка
     * не плодит дубли-лиды (обновляем существующий).
     *
     * @param  array<string,mixed>  $meta
     */
    private function recordLead(User $user, string $email, array $meta): void
    {
        $attributes = [
            'name' => $user->name,
            'contact' => $email,
            'is_promo_agreed' => (bool) ($meta['is_promo_agreed'] ?? false),
            'utm_source' => $meta['utm_source'] ?? null,
            'utm_medium' => $meta['utm_medium'] ?? null,
            'utm_campaign' => $meta['utm_campaign'] ?? null,
            'utm_content' => trim('[newsletter] '.($meta['utm_content'] ?? '')),
            'utm_term' => $meta['utm_term'] ?? null,
            'click_id' => $meta['click_id'] ?? null,
            'referrer' => $meta['referrer'] ?? null,
            'ip_address' => $meta['ip_address'] ?? null,
            'user_agent' => $meta['user_agent'] ?? null,
        ];

        Lead::updateOrCreate(
            ['email' => $email, 'utm_content' => $attributes['utm_content']],
            $attributes,
        );
    }
}
