<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Вебхук Точки — ЕДИНСТВЕННЫЙ автоматический триггер «оплачено -> доступ» в
 * проде (через PaymentObserver), но до сих пор без единого теста. Проверяем
 * отклонение неподписанных/чужих JWT и идемпотентность success-пути.
 *
 * Публичный ключ переопределяется через config (services.tochka.webhook_public_key),
 * что позволяет подписать тестовый JWT встроенной ниже одноразовой RSA-парой.
 * Ключ зафиксирован константой (а не генерируется openssl_pkey_new), чтобы тест
 * работал и на Windows (где генерация требует openssl.cnf), и в CI.
 */
class TochkaWebhookTest extends TestCase
{
    use RefreshDatabase;

    /** Одноразовая тестовая RSA-пара (НЕ боевой ключ Точки). */
    private const TEST_PRIVATE_PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvwIBADANBgkqhkiG9w0BAQEFAASCBKkwggSlAgEAAoIBAQC8oaiuhHot9iJI
3z+xch7KDj8IVAPJOdmIQYE4Js5iw1GmL0rYq0hfSFXO2QqTww7qXiTf3IXCg29L
Npb6Uk4Ryz8/V+eihRS0sSfiQc7VQ+yv/KS6gPbn4gPiW/B8tzfAYDf69FMgCXd7
B9LL6yW6GjgvePN9Fyc+OKFOAerQFGIIGbYio0n3U+P+pLi5deQ6vxJh2gDHOIx8
YB/gEiynUMItLbJrzdaAmKshEjb3GVemHd+MQ9/7Ds1wQJqUoX7TqRGp9MipELk9
qu/Bu92F5FZIeaGUVJBpxFXhiMAGlsaA/GwVCQuE0//8kx+5sahldy2X2571Mgx5
nHsXnSUZAgMBAAECggEABHYWVTpQ4XFm0i5lhT7bt4+qsfm6tTGnEW/rLHbOfst7
zOBldsZmScqeLOw5MdF1MtnTKXA/wZ/2K+M4oub7bbRO5KKhmdhn6vYdqV5BFA4t
NORWyQpvzIAt81aVU33J1cTwzgClTqaqqsA+nhALrmEcXxMPPzAi/3e7aOrmsNEg
Ktw0boWmgsGQpHgGl6XFNt6lejhUSzBt1svB4nT1sFyRasWhO0N7SZtkSEaW9gfi
SKEwAzW4pLZ8Nv8MwdA2/2eHvBa9eVCiUzr08lxGnEDdbiOET8DFLyPctg/Ekk27
2q3JT7TzLjuk8j2c/FDA2n/AjsjmOn9vskZrSjHE2QKBgQDuEzkNvv84ftjEbpmA
SVGiToPHICxHAonr21vwu4CkeFeE9JEKpX/rjbK3uAqG3srRH1doeFjSzvPJbOp7
iKJoAau7915EAFeoJ6FNBXPp1orT5H2ro9TPBy1CTNwHUuQ5yxAv+BMRxmrr5KUd
8Jb/Wd652TZuMQq5CLE5SrRnBwKBgQDK1W95XQVXwx8QCCu/Q3z40NZ8/f6R4Phz
LCYYULE4BvZNhBU4+BC3kavVE1UcAgkbFjj7JCXfhB2BSnoqy8Daw7LS8+nU6xmU
k63G2ngExjqC4qvywb6xNtCTwi4adzKwUTvYG+09mEmAyeGVHwf4HanI4Ranc5w6
Yl2e2Z3q3wKBgQDQAPWJIAXGq3TicqsknWp4f1a9JEvrIrmz2uzCMGAd0pLMtA0B
G0XfXOb3gxGXcpILEfIBcZxRWsU+iC16Dw+uBT+xM1gl25K6dR2FuKzkcjDLHsf5
rWMiGmgdlB9tOqvyHoufDYRDtHL4dMUamnii0zc4cyIONkTjE0gcATwLAwKBgQCx
f5vgodWWGotpVS0rYBzSBLdehEstT6k76IuhxaOAOx95cDe+Nd8zNUgg250kOGfN
i2Hr7JM0CYJkbU+BefLXvmAUGR0slVw6WA2/sdlLnEkB1ujQNFny7NwUId6EjIEQ
KNZs5Ot0dnsEOCavf4tSxmqY/tj7SsGRmhkBdMCsEwKBgQDOFyeLleSJsS751xNC
3WuMCgRz2kvQf2FszH2GnLibUr3uM46tpahreeCiWwKE/cbPEaCI20XkMP9Yq7pP
hCzeopgb4Ex0LMSgXQOmdmIxo2cRakNhyiNJkJfKwul9TdaDljpcPDYJhJpYUQby
6le5puetsuVUtcLUQMNNOTBRYw==
-----END PRIVATE KEY-----
PEM;

    /** JWK публичной части той же пары (кладём в config вместо боевого ключа Точки). */
    private const TEST_JWK = '{"kty":"RSA","e":"AQAB","n":"vKGoroR6LfYiSN8_sXIeyg4_CFQDyTnZiEGBOCbOYsNRpi9K2KtIX0hVztkKk8MO6l4k39yFwoNvSzaW-lJOEcs_P1fnooUUtLEn4kHO1UPsr_ykuoD25-ID4lvwfLc3wGA3-vRTIAl3ewfSy-sluho4L3jzfRcnPjihTgHq0BRiCBm2IqNJ91Pj_qS4uXXkOr8SYdoAxziMfGAf4BIsp1DCLS2ya83WgJirIRI29xlXph3fjEPf-w7NcECalKF-06kRqfTIqRC5ParvwbvdheRWSHmhlFSQacRV4YjABpbGgPxsFQkLhNP__JMfubGoZXctl9ue9TIMeZx7F50lGQ"}';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function useTestKey(): void
    {
        config(['services.tochka.webhook_public_key' => self::TEST_JWK]);
    }

    private function sign(array $payload): string
    {
        return JWT::encode($payload, self::TEST_PRIVATE_PEM, 'RS256');
    }

    private function postJwt(string $jwt)
    {
        return $this->call('POST', '/api/webhooks/tochka', [], [], [], ['CONTENT_TYPE' => 'application/jwt'], $jwt);
    }

    /** @test */
    public function garbage_body_is_rejected_with_401(): void
    {
        $this->useTestKey();

        $this->postJwt('not-a-jwt')->assertStatus(401);
    }

    /** @test */
    public function jwt_signed_with_an_unknown_key_is_rejected(): void
    {
        // НЕ переопределяем config -> контроллер берёт боевой ключ Точки, который
        // не соответствует нашей тестовой подписи -> подпись отклоняется.
        $jwt = $this->sign(['purpose' => 'Заказ №1', 'status' => 'APPROVED']);

        $this->postJwt($jwt)->assertStatus(401);
    }

    /** @test */
    public function valid_webhook_without_order_number_is_a_noop(): void
    {
        $this->useTestKey();
        $jwt = $this->sign(['purpose' => 'Пополнение счёта', 'status' => 'APPROVED']);

        $this->postJwt($jwt)->assertOk();
    }

    /**
     * Canonical Tochka one-shot status is APPROVED (card settle / SBP / Dolyame).
     *
     * @test
     */
    public function valid_approved_webhook_marks_payment_paid_and_is_idempotent(): void
    {
        $this->useTestKey();

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'G']);
        $course->groups()->attach($group->id);

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED']);

        // Первый вебхук: pending -> paid, доступ (группа) выдан.
        $this->postJwt($jwt)->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue($user->fresh()->groups->contains($group->id));

        // Второй идентичный вебхук: идемпотентность — статус уже paid, дублей групп нет.
        $this->postJwt($jwt)->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(1, $user->fresh()->groups()->count());
    }

    /**
     * Способ оплаты из поля `paymentType` (подтверждено докой Точки для события
     * acquiringInternetPayment: card/sbp/dolyame) сохраняется в
     * payments.payment_method — источник факта для «Юнит-экономики». Проверяем
     * все три значения без боевого платежа.
     *
     * @test
     *
     * @dataProvider paymentTypeProvider
     */
    public function payment_type_from_webhook_is_persisted(string $paymentType, string $expected): void
    {
        $this->useTestKey();

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'G']);
        $course->groups()->attach($group->id);

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        $jwt = $this->sign([
            'purpose' => "Заказ №{$payment->id}",
            'status' => 'APPROVED',
            'paymentType' => $paymentType,
        ]);

        $this->postJwt($jwt)->assertOk();

        $fresh = $payment->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertSame($expected, $fresh->payment_method);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function paymentTypeProvider(): array
    {
        return [
            'card' => ['card', 'card'],
            'sbp' => ['sbp', 'sbp'],
            'dolyame (рассрочка)' => ['dolyame', 'dolyame'],
        ];
    }

    // ================= H1359: ledger + resurrection/amount guards =================

    /**
     * Общий фикстур: pending-платёж на курс с одной группой доступа.
     *
     * @return array{0: Payment, 1: User, 2: Group}
     */
    private function makePendingPayment(int $amount = 4800, ?string $groupName = null): array
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $group = Group::create(['name' => $groupName ?? ('G-'.uniqid('', true))]);
        $course->groups()->attach($group->id);

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        return [$payment, $user, $group];
    }

    /**
     * Флаг OFF — паритет: платёж оплачен, отменён (возврат), затем повторный
     * success-вебхук ВОСКРЕШАЕТ его обратно в paid. Это фиксирует СЕГОДНЯШНЕЕ
     * (уязвимое) поведение — доказательство, что при выключенном флаге денежный
     * PR прод-инертен и ничего не меняет.
     *
     * @test
     */
    public function flag_off_paid_then_failed_then_replay_still_resurrects(): void
    {
        config(['features.tochka_webhook_guard' => false]);
        $this->useTestKey();
        [$payment] = $this->makePendingPayment();

        $this->postJwt($this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED']))->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);

        // Возврат админом.
        $payment->update(['status' => 'failed']);
        $this->assertSame('failed', $payment->fresh()->status);

        // Повторная (отличающаяся телом) success-доставка того же заказа.
        $this->postJwt($this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED', 'paymentType' => 'card']))->assertOk();

        // Без флага — воскрешение происходит, как и сегодня.
        $this->assertSame('paid', $payment->fresh()->status);
    }

    /**
     * Флаг ON — воскрешение отклонено: тот же сценарий paid→failed→replay
     * оставляет платёж failed, доступ не воскресает, а журнал фиксирует отказ.
     *
     * @test
     */
    public function flag_on_replay_after_reversal_is_refused(): void
    {
        config(['features.tochka_webhook_guard' => true]);
        $this->useTestKey();
        [$payment, $user, $group] = $this->makePendingPayment();

        $this->postJwt($this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED']))->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);

        $payment->update(['status' => 'failed']);

        $this->postJwt($this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED', 'paymentType' => 'card']))->assertOk();

        // Не воскрешён.
        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertDatabaseHas('payment_webhook_events', [
            'payment_id' => $payment->id,
            'decision' => PaymentWebhookEvent::DECISION_REJECTED_RESURRECTION,
        ]);
    }

    /**
     * Флаг ON — повтор той же доставки (тот же event_hash) — идемпотентный
     * no-op: второй раз группа не выдаётся, новой строки в журнале нет.
     *
     * @test
     */
    public function flag_on_duplicate_delivery_is_a_noop(): void
    {
        config(['features.tochka_webhook_guard' => true]);
        $this->useTestKey();
        [$payment, $user, $group] = $this->makePendingPayment();

        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED']);

        $this->postJwt($jwt)->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(1, PaymentWebhookEvent::count());
        $this->assertTrue($user->fresh()->groups->contains($group->id));

        // Тот же JWT снова — короткое замыкание, без второй строки и без дубля групп.
        $this->postJwt($jwt)->assertOk();
        $this->assertSame(1, PaymentWebhookEvent::count());
        $this->assertSame(1, $user->fresh()->groups()->count());
    }

    /**
     * Флаг ON — сумма из банка расходится с суммой заказа: доступ не выдаётся,
     * платёж остаётся pending, журнал фиксирует rejected_amount_mismatch.
     *
     * @test
     */
    public function flag_on_amount_mismatch_grants_nothing(): void
    {
        config(['features.tochka_webhook_guard' => true]);
        $this->useTestKey();
        [$payment, $user, $group] = $this->makePendingPayment(4800);

        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED', 'amount' => 9999]);
        $this->postJwt($jwt)->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertFalse($user->fresh()->groups->contains($group->id));
        $this->assertDatabaseHas('payment_webhook_events', [
            'payment_id' => $payment->id,
            'decision' => PaymentWebhookEvent::DECISION_REJECTED_AMOUNT_MISMATCH,
        ]);
    }

    /**
     * Флаг ON — success с совпадающей суммой всё так же выдаёт доступ и пишет
     * decision=applied. Гуард не ломает нормальный путь.
     *
     * @test
     */
    public function flag_on_matched_amount_success_grants(): void
    {
        config(['features.tochka_webhook_guard' => true]);
        $this->useTestKey();
        [$payment, $user, $group] = $this->makePendingPayment(4800);

        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED', 'amount' => 4800]);
        $this->postJwt($jwt)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue($user->fresh()->groups->contains($group->id));
        $this->assertDatabaseHas('payment_webhook_events', [
            'payment_id' => $payment->id,
            'decision' => PaymentWebhookEvent::DECISION_APPLIED,
        ]);
    }

    /**
     * Флаг OFF — журнал пишется всё равно (чисто аддитивно): обычный success
     * выдаёт доступ как раньше И оставляет строку decision=applied.
     *
     * @test
     */
    public function flag_off_records_ledger_row_on_delivery(): void
    {
        config(['features.tochka_webhook_guard' => false]);
        $this->useTestKey();
        [$payment, $user, $group] = $this->makePendingPayment();

        $this->postJwt($this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED']))->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue($user->fresh()->groups->contains($group->id));
        $this->assertDatabaseHas('payment_webhook_events', [
            'payment_id' => $payment->id,
            'decision' => PaymentWebhookEvent::DECISION_APPLIED,
        ]);
    }

    // ================= H2085 / H2337: hold ≠ capture · empty groups · purpose miss =================
    // Status matrix: docs/TOCHKA_SETTLEMENT_STATUS_MATRIX_2026-08-07.md

    /**
     * An authorization is only a hold: it never marks paid or grants access and
     * is journalled as hold_not_captured.
     *
     * @test
     *
     * @dataProvider holdStatusProvider
     */
    public function authorized_hold_status_does_not_grant(string $bankStatus): void
    {
        $this->useTestKey();
        [$payment, $user, $group] = $this->makePendingPayment();

        $this->postJwt($this->sign([
            'purpose' => "Заказ №{$payment->id}",
            'status' => $bankStatus,
        ]))->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertFalse($user->fresh()->groups->contains($group->id));
        $this->assertDatabaseHas('payment_webhook_events', [
            'payment_id' => $payment->id,
            'decision' => PaymentWebhookEvent::DECISION_HOLD_NOT_CAPTURED,
            'bank_status' => $bankStatus,
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function holdStatusProvider(): array
    {
        return [
            'authorized lower' => ['authorized'],
            'AUTHORIZED upper' => ['AUTHORIZED'],
        ];
    }

    /**
     * Intermediate/unknown bank statuses stay pending (not hold, not settled).
     *
     * @test
     *
     * @dataProvider nonCaptureStatusProvider
     */
    public function non_capture_status_does_not_mark_payment_paid(string $bankStatus): void
    {
        $this->useTestKey();
        [$payment, $user, $group] = $this->makePendingPayment();

        $this->postJwt($this->sign([
            'purpose' => "Заказ №{$payment->id}",
            'status' => $bankStatus,
        ]))->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertFalse($user->fresh()->groups->contains($group->id));
    }

    /** @return array<string, array{0: string}> */
    public static function nonCaptureStatusProvider(): array
    {
        return [
            'processing' => ['processing'],
            'pending bank' => ['PENDING'],
        ];
    }

    /**
     * Doc-backed and historical success aliases all mark paid.
     *
     * @test
     *
     * @dataProvider settledStatusProvider
     */
    public function settled_bank_status_marks_payment_paid(string $bankStatus): void
    {
        $this->useTestKey();
        [$payment, $user, $group] = $this->makePendingPayment();

        $this->postJwt($this->sign([
            'purpose' => "Заказ №{$payment->id}",
            'status' => $bankStatus,
        ]))->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue($user->fresh()->groups->contains($group->id));
    }

    /** @return array<string, array{0: string}> */
    public static function settledStatusProvider(): array
    {
        return [
            'APPROVED (Tochka docs)' => ['APPROVED'],
            'approved lower' => ['approved'],
            'paid alias' => ['paid'],
            'captured alias' => ['captured'],
            'completed alias' => ['completed'],
        ];
    }

    /** @test */
    public function later_capture_after_authorized_hold_grants_access(): void
    {
        $this->useTestKey();
        [$payment, $user, $group] = $this->makePendingPayment();

        $this->postJwt($this->sign([
            'purpose' => "Заказ №{$payment->id}",
            'status' => 'authorized',
        ]))->assertOk();
        $this->assertSame('pending', $payment->fresh()->status);

        $this->postJwt($this->sign([
            'purpose' => "Заказ №{$payment->id}",
            'status' => 'APPROVED',
        ]))->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue($user->fresh()->groups->contains($group->id));
    }

    /** @test */
    public function missing_groups_fail_closed_then_same_delivery_succeeds_after_repair(): void
    {
        $this->useTestKey();

        $user = User::factory()->create();
        $course = Course::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        $jwt = $this->sign([
            'purpose' => "Заказ №{$payment->id}",
            'status' => 'completed',
        ]);

        // grantAccess throws inside the transaction: paid and the delivery ledger
        // both roll back, so Tochka can retry this exact JWT after configuration repair.
        $this->postJwt($jwt)->assertStatus(500);

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, $user->fresh()->groups()->count());
        $this->assertSame(0, PaymentWebhookEvent::count());

        $group = Group::create(['name' => 'Repaired group']);
        $course->groups()->attach($group->id);

        $this->postJwt($jwt)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertTrue($user->fresh()->groups->contains($group->id));
        $this->assertDatabaseHas('payment_webhook_events', [
            'payment_id' => $payment->id,
            'decision' => PaymentWebhookEvent::DECISION_APPLIED,
        ]);
    }

    /**
     * H2085 gap 3 — purpose without «Заказ №{id}»: soft 200 (intentional, bank
     * retry hygiene) + UNMATCHED journal. Access not granted.
     *
     * @test
     */
    public function purpose_parse_miss_is_soft_200_unmatched_no_access(): void
    {
        $this->useTestKey();
        [$payment, $user, $group] = $this->makePendingPayment();

        $this->postJwt($this->sign([
            'purpose' => 'Пополнение счёта без номера',
            'status' => 'APPROVED',
        ]))->assertOk();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertFalse($user->fresh()->groups->contains($group->id));
        $this->assertDatabaseHas('payment_webhook_events', [
            'decision' => PaymentWebhookEvent::DECISION_UNMATCHED,
        ]);
    }

    // ================= H2337: status matrix lock (failure row + hold≠APPROVED) =================
    // Matrix: docs/TOCHKA_SETTLEMENT_STATUS_MATRIX_2026-08-07.md
    // Hold never paid; APPROVED settles; failures mark failed without access.

    /**
     * Bank failure statuses mark the payment failed and never grant access.
     *
     * @test
     *
     * @dataProvider failureStatusProvider
     */
    public function failure_bank_status_marks_payment_failed(string $bankStatus): void
    {
        $this->useTestKey();
        [$payment, $user, $group] = $this->makePendingPayment();

        $this->postJwt($this->sign([
            'purpose' => "Заказ №{$payment->id}",
            'status' => $bankStatus,
        ]))->assertOk();

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertFalse($user->fresh()->groups->contains($group->id));
    }

    /** @return array<string, array{0: string}> */
    public static function failureStatusProvider(): array
    {
        return [
            'rejected' => ['rejected'],
            'canceled' => ['canceled'],
            'cancelled alias' => ['cancelled'],
            'failed' => ['failed'],
        ];
    }

    /**
     * Explicit regression: AUTHORIZED hold must never land as paid even when
     * APPROVED is a success alias (#1146 soft-back must not re-open hold-as-paid).
     *
     * @test
     */
    public function authorized_upper_never_equals_approved_success(): void
    {
        $this->useTestKey();
        [$paymentHold, $userHold, $groupHold] = $this->makePendingPayment(4800, 'H2337-hold');
        [$paymentOk, $userOk, $groupOk] = $this->makePendingPayment(4800, 'H2337-approved');

        $this->postJwt($this->sign([
            'purpose' => "Заказ №{$paymentHold->id}",
            'status' => 'AUTHORIZED',
        ]))->assertOk();
        $this->postJwt($this->sign([
            'purpose' => "Заказ №{$paymentOk->id}",
            'status' => 'APPROVED',
        ]))->assertOk();

        $this->assertSame('pending', $paymentHold->fresh()->status);
        $this->assertFalse($userHold->fresh()->groups->contains($groupHold->id));
        $this->assertDatabaseHas('payment_webhook_events', [
            'payment_id' => $paymentHold->id,
            'decision' => PaymentWebhookEvent::DECISION_HOLD_NOT_CAPTURED,
            'bank_status' => 'AUTHORIZED',
        ]);

        $this->assertSame('paid', $paymentOk->fresh()->status);
        $this->assertTrue($userOk->fresh()->groups->contains($groupOk->id));
    }

    /** @test */
    public function approved_webhook_pays_gift_payment_and_issues_certificate(): void
    {
        // H3334 e2e по ПРОДАКШЕН-триггеру: единственный автопереход «оплачено»
        // в проде — этот вебхук. Прямое update(['status'=>'paid']) в
        // GiftCertificateTest вебхук-контракт не проверяет.
        $this->useTestKey();

        $buyer = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $buyer->id,
            'course_id' => null,
            'amount' => 6000.0,
            'deposit_credit_applied' => 0.0,
            'tariff' => 'gift',
            'status' => 'pending',
            'claim_meta' => [
                'gift_tariff_key' => 'full',
                'gift_tariff_title' => 'Весь курс',
                'gift_start_block' => null,
                'gift_end_block' => null,
            ],
        ]);

        $jwt = $this->sign(['purpose' => "Заказ №{$payment->id}", 'status' => 'APPROVED']);
        $this->postJwt($jwt)->assertOk();

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertDatabaseCount('gift_certificates', 1);

        // Покупателю доступ НЕ открывается — только выпуск сертификата.
        $this->assertSame(0, $buyer->groups()->count());
    }
}
