<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportContact;
use App\Models\User;
use App\Services\AttributionService;
use App\Services\Support\SupportDmLinkInvite;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * H3542: self-service связывание Telegram-контакта с кабинетом по ссылке-
 * приглашению из DM саппорт-бота (/support/link/{token}).
 *
 * Приватность: ответ НЕ различает «такой email уже был / создали нового» и
 * «связали сейчас / уже связан» — одна и та же страница успеха (анти-enumeration);
 * в логах только id; email наружу не эхом. Токен — одноразовая capability
 * (plaintext в ссылке, SHA-256 в БД, TTL), «использован» = у контакта появился
 * linked_user_id; гонка двух сабмитов разрешается атомарным whereNull-апдейтом,
 * первый писатель выигрывает.
 */
class TelegramSupportLinkController extends Controller
{
    public function show(Request $request, string $token)
    {
        abort_unless(config('features.support_dm_link_invite'), 404);

        if (TelegramSupportContact::findActiveByLinkToken($token) !== null) {
            return view('support.telegram-link', [
                'state' => 'form',
                'token' => $token,
            ]);
        }

        // Неактивный по любой причине живой токен (уже связан / только что
        // связали этим же сабмитом) — спокойная страница успеха. Полностью
        // неизвестный или протухший — 404 без деталей.
        abort_unless($this->contactByTokenHash($token) !== null, 404);

        return view('support.telegram-link', ['state' => 'success']);
    }

    public function submit(Request $request, string $token): RedirectResponse
    {
        abort_unless(config('features.support_dm_link_invite'), 404);

        $rlKey = 'support-link:'.$request->ip();
        if (RateLimiter::tooManyAttempts($rlKey, 5)) {
            abort(429, 'Слишком частые запросы. Подождите минуту.');
        }
        RateLimiter::hit($rlKey, 60);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        DB::transaction(function () use ($token, $validated): void {
            /** @var TelegramSupportContact|null $contact */
            $contact = TelegramSupportContact::query()
                ->where('link_token_hash', hash('sha256', $token))
                ->lockForUpdate()
                ->first();

            // Протух/погашен — единый «успех» без действия, чтобы форма не
            // становилась оракулом о состоянии токена.
            if ($contact === null || ! $this->tokenUsable($contact)) {
                return;
            }

            [$user] = $this->findOrCreateUser(
                User::normalizeEmail($validated['email']) ?? '',
                $contact,
            );

            // Первый писатель выигрывает: существующий линк не перетираем.
            $linkedNow = TelegramSupportContact::query()
                ->whereKey($contact->id)
                ->whereNull('linked_user_id')
                ->update(['linked_user_id' => $user->id]);

            $fresh = $contact->refresh();

            // Query-builder update не зажигает saved-observer модели контакта,
            // поэтому private-чат досинхронизируем вручную (та же логика:
            // только private, чужой линк не перетираем).
            if ($linkedNow === 1
                && $fresh->chat
                && $fresh->chat->type === 'private'
                && $fresh->chat->linked_user_id !== $user->id
            ) {
                $fresh->chat->forceFill(['linked_user_id' => $user->id])->save();
            }

            if ($linkedNow === 1) {
                SupportAiReplyEvent::create([
                    'telegram_support_message_id' => null,
                    'event_type' => 'dm_contact_linked',
                    'meta' => [
                        'via' => SupportDmLinkInvite::VIA,
                        'contact_id' => $fresh->id,
                        'user_id' => $user->id,
                        'telegram_support_chat_id' => $fresh->telegram_support_chat_id,
                    ],
                ]);

                Log::info('H3542: telegram-контакт связан с пользователем', [
                    'contact_id' => $fresh->id,
                    'user_id' => $user->id,
                    'telegram_support_chat_id' => $fresh->telegram_support_chat_id,
                ]);
            }
        });

        return redirect()
            ->route('support.telegram.link', ['token' => $token])
            ->with('support_linked', true);
    }

    private function contactByTokenHash(string $token): ?TelegramSupportContact
    {
        return TelegramSupportContact::query()
            ->where('link_token_hash', hash('sha256', $token))
            ->first();
    }

    private function tokenUsable(TelegramSupportContact $contact): bool
    {
        return $contact->linked_user_id === null
            && $contact->link_token_expires_at !== null
            && $contact->link_token_expires_at->isFuture();
    }

    /**
     * @return array{0: User, 1: bool} [user, wasCreated]
     */
    private function findOrCreateUser(string $email, TelegramSupportContact $contact): array
    {
        $existing = User::where('email', $email)->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        // Беспарольный аккаунт — тот же путь создания, что у соц-входа и H324:
        // вход по magic-link или восстановлению пароля.
        $user = User::create([
            'name' => $contact->name ?: ($contact->username ? '@'.$contact->username : 'Читатель'),
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
        ]);

        app(AttributionService::class)->applyToNewUser($user);

        return [$user, true];
    }
}
