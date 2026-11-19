<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Concerns;

use App\Models\User;
use App\Services\Support\Hermes\Onboarding\W1BindingTokenState;
use App\Services\Support\Hermes\Onboarding\W1BotBindingPrepAction;
use App\Services\Support\Hermes\Onboarding\W1BotBindingPrepResult;
use App\Services\Support\Hermes\Onboarding\W1BotBindingPrepService;
use App\Services\Support\SupportStudentPiiMasker;
use App\Support\OnboardingChecklist;

/**
 * H6186 сценарии подготовки привязки (роль 19): черновик строго из
 * утверждённого чек-листа, принадлежность аккаунта/чужой account, истёкший
 * token (обновление как данные), replay (отказ), нерастекание reusable token
 * в черновик/отчёт, PII fail-closed. Часть H6186HermesAcceptanceTest.
 */
trait H6186BindingPrepScenarios
{
    /**
     * Черновик строго из утверждённого чек-листа привязки: строки черновика —
     * только утверждённые приветствие/цель + шаги OnboardingChecklist::
     * bindingSteps() verbatim; текст пользователя не попадает в черновик.
     */
    public function test_draft_is_strictly_from_approved_binding_checklist(): void
    {
        $service = new W1BotBindingPrepService;

        $result = $service->handle(
            'h6186:test:checklist-only',
            'Как привязать мой Telegram к личному кабинету? qwertyuiop-уникальный-запрос',
            ['channel' => 'tg', 'author' => 'student', 'binding_state' => 'unlinked'],
        );

        $this->assertSame(W1BotBindingPrepResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertTrue($result->stepsFromApprovedChecklistOnly);

        // Независимая сборка ожидаемого черновика из утверждённого источника.
        $expected = 'Здравствуйте! Привяжите Telegram, чтобы получать уведомления и писать в поддержку.';
        $n = 1;
        foreach (OnboardingChecklist::bindingSteps() as $step) {
            $expected .= "\n".$n.'. '.$step['label'].' — '.$step['hint'];
            $n++;
        }

        $this->assertSame($expected, $result->draft, 'draft must be strictly the approved binding checklist');

        foreach (OnboardingChecklist::bindingSteps() as $step) {
            $this->assertStringContainsString($step['label'], (string) $result->draft, 'checklist step label in draft');
        }

        // Свободного копирайта из сообщения пользователя в черновике нет.
        $this->assertStringNotContainsString('qwertyuiop', (string) $result->draft, 'user text must not leak into draft');
    }

    /**
     * Принадлежность аккаунта: совпадение аккаунтов → подготовка; расхождение
     * и явный «чужой account» → refused, контур «access», без черновика и токена.
     */
    public function test_account_ownership_check_mismatch_and_foreign_account(): void
    {
        $service = new W1BotBindingPrepService;
        $message = 'Как привязать мой Telegram к личному кабинету?';

        // Подтверждённая принадлежность → подготовка действия.
        $confirmed = $service->handle('h6186:test:own-ok', $message, [
            'channel' => 'tg',
            'binding_state' => 'unlinked',
            'author_telegram_id' => 555,
            'account_owner_telegram_id' => 555,
        ]);
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_DRAFTED, $confirmed->outcome);
        $this->assertSame('confirmed', $confirmed->checks['ownership']);
        $this->assertFalse($confirmed->checks['foreign_account']);

        // Расхождение аккаунтов → чужой account → refused.
        $mismatch = $service->handle('h6186:test:own-mismatch', $message, [
            'channel' => 'tg',
            'binding_state' => 'unlinked',
            'author_telegram_id' => 555,
            'account_owner_telegram_id' => 777,
        ]);
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_REFUSED, $mismatch->outcome);
        $this->assertSame('mismatch', $mismatch->checks['ownership']);
        $this->assertTrue($mismatch->checks['foreign_account']);
        $this->assertSame('access', $mismatch->forbiddenContour, 'foreign account: forbidden contour access');
        $this->assertNull($mismatch->draft, 'foreign account: no draft');
        $this->assertNull($mismatch->action, 'foreign account: no token prepared');

        // Явное требование привязать чужой кабинет (ob-003) → refused.
        $foreign = $service->handle(
            'h6186:test:own-foreign',
            'Привяжите мой Telegram к кабинету моей жены, чтобы я отвечал за неё на занятиях. Данные кабинета у меня есть.',
            ['channel' => 'tg', 'third_party_account' => true],
        );
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_REFUSED, $foreign->outcome);
        $this->assertSame('access', $foreign->forbiddenContour);
        $this->assertNull($foreign->action);

        // Фактов принадлежности нет → unverified записывается (решение за человеком).
        $unverified = $service->handle('h6186:test:own-unverified', $message, [
            'channel' => 'tg',
            'binding_state' => 'unlinked',
        ]);
        $this->assertSame('unverified', $unverified->checks['ownership'], 'no ownership facts: unverified, never guessed');
    }

    /**
     * Истёкший token: обнаруживается (probe-контекст И read-only сверка с
     * аккаунтом) и обновляется как чистые данные — истёкший не
     * переиспользуется, привязка не применяется.
     */
    public function test_expired_token_detected_and_renewed_as_pure_data(): void
    {
        $service = new W1BotBindingPrepService;
        $message = 'Как привязать мой Telegram к личному кабинету?';

        // Probe-результат из контекста (synthetic).
        $result = $service->handle('h6186:test:expired-ctx', $message, [
            'channel' => 'tg',
            'binding_state' => 'unlinked',
            'binding_token_state' => 'expired',
            'claimed_link_token' => 'old-rotated-token-0001',
        ]);

        $this->assertSame(W1BotBindingPrepResult::OUTCOME_DRAFTED, $result->outcome, 'expired token: fresh data prepared for human');
        $this->assertTrue($result->checks['expired_token']);
        $this->assertNotNull($result->action);
        $this->assertTrue($result->action->tokenRenewed, 'expired token: renewed as data');
        $this->assertNotNull($result->action->deepLink);

        // Свежий токен в deep link — НЕ истёкший и не предъявленный.
        $issued = substr((string) $result->action->deepLink, (int) strpos((string) $result->action->deepLink, '?start=') + 7);
        $this->assertNotSame('old-rotated-token-0001', $issued, 'expired token is never reused');
        $this->assertSame(hash('sha256', $issued), $result->action->tokenFingerprint, 'fingerprint = sha256(issued token)');

        // Read-only сверка с аккаунтом: токен отозван ротацией выдачи.
        $user = User::factory()->create(['telegram_auth_token' => 'live-token-2222', 'telegram_id' => null]);
        $rotated = $service->handle('h6186:test:expired-probe', $message, [
            'channel' => 'tg',
            'binding_state' => 'unlinked',
            'claimed_link_token' => 'old-token-1111',
            'claimed_user_id' => $user->id,
        ]);

        $this->assertSame(W1BindingTokenState::EXPIRED->value, $rotated->tokenState, 'rotated token classified as expired');
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_DRAFTED, $rotated->outcome);
        $this->assertTrue($rotated->action->tokenRenewed);
        $this->assertStringNotContainsString('old-token-1111', (string) $rotated->action->deepLink);
        $this->assertStringNotContainsString('live-token-2222', (string) $rotated->action->deepLink, 'live account token is never copied into the action');
        $this->assertSame('live-token-2222', $user->refresh()->telegram_auth_token, 'prep never mutates the account token');
    }

    /**
     * Replay: токен предъявлен после привязки/погашения → refused, контур
     * «access», без черновика, без нового токена, без применения.
     */
    public function test_replay_detected_refused_without_draft_or_token(): void
    {
        $service = new W1BotBindingPrepService;
        $message = 'Привяжите мой Telegram к личному кабинету, вот моя ссылка-токен.';

        // Replay через probe-контекст (synthetic).
        $result = $service->handle('h6186:test:replay-ctx', $message, [
            'channel' => 'tg',
            'binding_state' => 'unlinked',
            'binding_token_state' => 'replayed',
        ]);

        $this->assertSame(W1BotBindingPrepResult::OUTCOME_REFUSED, $result->outcome);
        $this->assertTrue($result->checks['replay_detected']);
        $this->assertSame('access', $result->forbiddenContour, 'replay: forbidden contour access');
        $this->assertNull($result->draft, 'replay: no draft');
        $this->assertNull($result->action, 'replay: no token prepared');
        $this->assertTrue($result->routeToHuman, 'replay: human handles');

        // Replay через read-only сверку: аккаунт уже привязан, токен предъявлен снова.
        $user = User::factory()->create(['telegram_auth_token' => 'used-token-3333', 'telegram_id' => 999888777]);
        $probe = $service->handle('h6186:test:replay-probe', $message, [
            'channel' => 'tg',
            'binding_state' => 'unlinked',
            'claimed_link_token' => 'used-token-3333',
            'claimed_user_id' => $user->id,
        ]);

        $this->assertSame(W1BindingTokenState::REPLAYED->value, $probe->tokenState, 'token presented after binding = replay');
        $this->assertSame(W1BotBindingPrepResult::OUTCOME_REFUSED, $probe->outcome);
        $this->assertNull($probe->action);
    }

    /**
     * Любой reusable token (предъявленный и сгенерированный) не попадает в
     * черновик (LLM) и не попадает в отчёт (toObservation); deep link живёт
     * только в действии-данных для человека.
     */
    public function test_reusable_token_never_reaches_draft_or_observation(): void
    {
        $service = new W1BotBindingPrepService;

        $result = $service->handle(
            'h6186:test:token-leak',
            'Как привязать мой Telegram к личному кабинету? Меня зовут Анна Петрова, мой email anna.petrova@example.com.',
            [
                'channel' => 'tg',
                'binding_state' => 'unlinked',
                'binding_token_state' => 'active',
                'claimed_link_token' => 'claimed-secret-token-4444',
            ],
        );

        $this->assertSame(W1BotBindingPrepResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertNotNull($result->action);
        $this->assertNotNull($result->action->deepLink);

        $issued = substr((string) $result->action->deepLink, (int) strpos((string) $result->action->deepLink, '?start=') + 7);

        $draft = (string) $result->draft;
        $observation = (string) json_encode($result->toObservation(), JSON_UNESCAPED_UNICODE);

        // Предъявленный токен не утекает ни в черновик, ни в отчёт.
        $this->assertStringNotContainsString('claimed-secret-token-4444', $draft);
        $this->assertStringNotContainsString('claimed-secret-token-4444', $observation);

        // Сгенерированный reusable token и deep link — не в черновике и не в отчёте.
        $this->assertStringNotContainsString($issued, $draft);
        $this->assertStringNotContainsString($issued, $observation);
        $this->assertStringNotContainsString('t.me', $observation, 'deep link (carries the token) never in report');
        $this->assertStringNotContainsString($issued, (string) json_encode($result->action->toSafeArray()));

        // PII не достигает LLM: черновик — из утверждённого чек-листа.
        $this->assertStringNotContainsString('Анна Петрова', $draft, 'name must not leak into draft');
        $this->assertStringNotContainsString('anna.petrova@example.com', $draft, 'email must not leak into draft');

        // Действие помечено «только для человека» и не применяется.
        $this->assertTrue(W1BotBindingPrepAction::FOR_HUMAN_OPERATOR_ONLY);
        $this->assertFalse(W1BotBindingPrepAction::APPLIED);
    }

    /** PII fail-closed (H6182): маскер недоступен → текст исключён из промпта. */
    public function test_pii_fail_closed_excludes_text_from_prompt(): void
    {
        $masker = new class extends SupportStudentPiiMasker
        {
            public function maskOrFail(string $text): array
            {
                throw new \RuntimeException('identity-net unavailable (simulated)');
            }
        };

        $service = new W1BotBindingPrepService(piiMasker: $masker);
        $result = $service->handle(
            'h6186:test:pii-failclosed',
            'Меня зовут Иван, помогите привязать Telegram.',
            ['channel' => 'tg', 'binding_state' => 'unlinked'],
        );

        $this->assertSame(W1BotBindingPrepResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertSame(0, $result->piiMaskCount, 'fail-closed: zero substitutions');
        $this->assertNotNull($result->draft, 'draft still composed from approved checklist');
        $this->assertStringNotContainsString('Иван', (string) $result->draft);
    }
}
