<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\GiftCertificateIssuedMail;
use App\Models\Course;
use App\Models\GiftCertificate;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Services\GiftCertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H3334 — подарочные сертификаты: покупка (tariff='gift') → выпуск
 * одноразового кода (только sha256-хэш в БД) → активация получателем
 * через штатную тарифную модель → одноразовость → отзыв при возврате.
 */
class GiftCertificateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/abc',
                    'paymentLinkId' => 'tochka_tx_001',
                ],
            ], 200),
        ]);
    }

    private function tariffWithGroup(int $price = 6000): Tariff
    {
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        return Tariff::factory()->for($course)->create(['price' => $price]);
    }

    private function paidGiftPayment(Tariff $tariff, User $buyer): Payment
    {
        $payment = Payment::create([
            'user_id' => $buyer->id,
            'course_id' => $tariff->course_id,
            'amount' => (float) $tariff->price,
            'deposit_credit_applied' => 0.0,
            'tariff' => 'gift',
            'status' => 'pending',
            'claim_meta' => [
                'gift_tariff_key' => $tariff->accessKey(),
                'gift_tariff_title' => (string) $tariff->title,
                'gift_start_block' => null,
                'gift_end_block' => null,
            ],
        ]);

        // Вебхук банка: pending → paid, дальше всё делает Payment::booted().
        $payment->update(['status' => 'paid']);

        return $payment;
    }

    /** @test */
    public function flag_off_gift_param_is_ignored_and_checkout_stays_legacy(): void
    {
        $user = User::factory()->create();
        $tariff = $this->tariffWithGroup();

        config(['features.gift_certificates' => false]);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id, 'gift' => '1'])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', ['tariff' => $tariff->accessKey()]);
        $this->assertDatabaseCount('gift_certificates', 0);
    }

    /** @test */
    public function gift_checkout_creates_pending_payment_with_snapshot_and_no_certificate_yet(): void
    {
        $user = User::factory()->create();
        $tariff = $this->tariffWithGroup();

        config(['features.gift_certificates' => true]);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id, 'gift' => '1'])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', [
            'user_id' => $user->id,
            'tariff' => 'gift',
            'status' => 'pending',
            'amount' => (string) $tariff->price,
        ]);

        $payment = Payment::query()->latest('id')->first();
        $this->assertSame($tariff->accessKey(), $payment->claimMeta('gift_tariff_key'));
        $this->assertSame((string) $tariff->title, $payment->claimMeta('gift_tariff_title'));

        // Сертификат выпускается только по ОПЛАТЕ, не при создании заказа.
        $this->assertDatabaseCount('gift_certificates', 0);
    }

    /** @test */
    public function paid_gift_issues_certificate_without_buyer_access_and_without_raw_code_in_db(): void
    {
        $buyer = User::factory()->create();
        $tariff = $this->tariffWithGroup();

        config(['features.gift_certificates' => true]);
        $payment = $this->paidGiftPayment($tariff, $buyer);

        $certificate = GiftCertificate::query()->where('payment_id', $payment->id)->firstOrFail();

        // Покупателю доступ НЕ открыт (fail-условие хендоффа).
        $this->assertSame(0, $buyer->groups()->count());

        // В БД только sha256-хэш; сырой код уходит ровно одним письмом.
        Mail::assertQueued(GiftCertificateIssuedMail::class, 1);
        Mail::assertQueued(GiftCertificateIssuedMail::class, function (GiftCertificateIssuedMail $mail) use ($certificate): bool {
            $normalized = strtoupper(str_replace('-', '', $mail->activationCode));
            $this->assertSame(hash('sha256', $normalized), $certificate->code_hash);
            $this->assertSame(substr($normalized, -4), $certificate->code_hint);
            $this->assertStringStartsWith('GIFT-', $mail->activationCode);

            return true;
        });
    }

    /** @test */
    public function activation_grants_recipient_access_through_tariff_model(): void
    {
        $buyer = User::factory()->create();
        $recipient = User::factory()->create();
        $tariff = $this->tariffWithGroup();

        config(['features.gift_certificates' => true]);
        $payment = $this->paidGiftPayment($tariff, $buyer);

        $code = null;
        Mail::assertQueued(GiftCertificateIssuedMail::class, function (GiftCertificateIssuedMail $mail) use (&$code): bool {
            $code = $mail->activationCode;

            return true;
        });

        $response = $this->actingAs($recipient)
            ->post(route('gift.activate.attempt'), ['code' => strtolower(str_replace('-', ' ', (string) $code))])
            ->assertRedirect(route('student.dashboard'));

        // Доступ РОВНО как при обычной покупке этого тарифа: группы курса + запись.
        $expectedGroups = $tariff->course->groups()->pluck('groups.id')->all();
        $this->assertSame([], array_diff($expectedGroups, $recipient->groups()->pluck('groups.id')->all()));
        $this->assertTrue($recipient->courses()->whereKey($tariff->course_id)->exists());

        // Платёж получателя несёт ключ доступа тарифа и ноль денег/зачётов:
        // депозиты получателя за подарок не гасятся.
        $recipientPayment = Payment::query()
            ->where('user_id', $recipient->id)
            ->where('tariff', $tariff->accessKey())
            ->firstOrFail();
        $this->assertEquals(0, (float) $recipientPayment->amount);
        $this->assertEquals(0.0, (float) $recipientPayment->deposit_credit_applied);
        $this->assertSame(
            $recipientPayment->id,
            GiftCertificate::query()->where('payment_id', $payment->id)->value('recipient_payment_id'),
        );
    }

    /** @test */
    public function second_activation_of_same_code_is_rejected_single_use_enforced(): void
    {
        $buyer = User::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $tariff = $this->tariffWithGroup();

        config(['features.gift_certificates' => true]);
        $this->paidGiftPayment($tariff, $buyer);

        Mail::assertQueued(GiftCertificateIssuedMail::class, function (GiftCertificateIssuedMail $mail) use (&$code): bool {
            $code = $mail->activationCode;

            return true;
        });

        $this->actingAs($first)
            ->post(route('gift.activate.attempt'), ['code' => $code])
            ->assertRedirect(route('student.dashboard'));

        $this->actingAs($second)
            ->post(route('gift.activate.attempt'), ['code' => $code])
            ->assertSessionHasErrors('code');

        // Второй активатор доступа не получил.
        $this->assertSame(0, $second->groups()->count());
    }

    /** @test */
    public function unknown_or_malformed_code_is_rejected(): void
    {
        $recipient = User::factory()->create();

        config(['features.gift_certificates' => true]);

        $this->actingAs($recipient)
            ->from(route('gift.activate'))
            ->post(route('gift.activate.attempt'), ['code' => 'GIFT-ZZZZ-ZZZZ-ZZZZ-ZZZZ'])
            ->assertSessionHasErrors('code');
    }

    /** @test */
    public function guest_activation_redirects_to_login(): void
    {
        config(['features.gift_certificates' => true]);

        $this->get(route('gift.activate'))->assertRedirect(route('login'));
    }

    /** @test */
    public function flag_off_gift_routes_are_404(): void
    {
        config(['features.gift_certificates' => false]);

        $this->get(route('gift.activate'))->assertNotFound();
        $this->get('/gift/verify/GC-SOMETHING123')->assertNotFound();
    }

    /** @test */
    public function verify_page_shows_certificate_status_without_personal_data(): void
    {
        $buyer = User::factory()->create(['name' => 'Секретный Покупатель']);
        $tariff = $this->tariffWithGroup();

        config(['features.gift_certificates' => true]);
        $this->paidGiftPayment($tariff, $buyer);

        $certificate = GiftCertificate::query()->firstOrFail();

        $content = $this->get(route('gift.verify', ['number' => $certificate->number]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($certificate->number, $content);
        $this->assertStringContainsString('действителен', $content);
        // Персональных данных покупателя на публичной странице нет.
        $this->assertStringNotContainsString('Секретный Покупатель', $content);

        // Неизвестный номер — честная страница «не найден», не 500.
        $this->get(route('gift.verify', ['number' => 'GC-UNKNOWN00000']))
            ->assertOk()
            ->assertSee('не найден');
    }

    /** @test */
    public function payment_reversal_revokes_unactivated_certificate(): void
    {
        $buyer = User::factory()->create();
        $recipient = User::factory()->create();
        $tariff = $this->tariffWithGroup();

        config(['features.gift_certificates' => true]);
        $payment = $this->paidGiftPayment($tariff, $buyer);

        $code = null;
        Mail::assertQueued(GiftCertificateIssuedMail::class, function (GiftCertificateIssuedMail $mail) use (&$code): bool {
            $code = $mail->activationCode;

            return true;
        });

        // Возврат оплаты: paid → canceled (как вебхук возврата Точки).
        $payment->update(['status' => 'canceled']);
        $this->assertSame(
            GiftCertificate::STATUS_REVOKED,
            GiftCertificate::query()->where('payment_id', $payment->id)->value('status'),
        );

        // Отозванный код не активируется и доступ не открывает.
        $service = app(GiftCertificateService::class);

        $this->expectException(\DomainException::class);
        try {
            $service->redeem((string) $code, $recipient);
        } finally {
            $this->assertSame(0, $recipient->groups()->count());
        }
    }

    /** @test */
    public function idempotent_issue_on_second_paid_transition_does_not_regenerate_code(): void
    {
        $buyer = User::factory()->create();
        $tariff = $this->tariffWithGroup();

        config(['features.gift_certificates' => true]);
        $payment = $this->paidGiftPayment($tariff, $buyer);

        $firstHash = GiftCertificate::query()->where('payment_id', $payment->id)->value('code_hash');

        // Повторный paid-вебхук (resurrection-guard уже отсёк fireOnPaid —
        // проверяем сам сервис на идемпотентность напрямую).
        app(GiftCertificateService::class)->issueForPayment($payment);

        $this->assertDatabaseCount('gift_certificates', 1);
        $this->assertSame($firstHash, GiftCertificate::query()->where('payment_id', $payment->id)->value('code_hash'));
    }

    /** @test */
    public function gift_checkout_purpose_keeps_tochka_webhook_order_number_invariant(): void
    {
        $user = User::factory()->create();
        $tariff = $this->tariffWithGroup();

        config(['features.gift_certificates' => true]);

        $this->actingAs($user)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id, 'gift' => '1'])
            ->assertRedirect();

        // Контракт вебхука Точки: purpose начинается с «Заказ №{id}»
        // (WebhookController матчит строго /Заказ №(\d+)/). Body может прийти
        // и строкой — декодируем сами.
        Http::assertSent(function ($request): bool {
            $payload = json_decode((string) $request->body(), true);
            // Конверт Точки: purpose внутри Data.
            $purpose = $payload['Data']['purpose'] ?? $payload['purpose'] ?? '';

            return str_starts_with((string) $purpose, 'Заказ №');
        });
    }
}
