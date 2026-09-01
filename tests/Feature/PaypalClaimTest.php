<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\PaypalClaimReceivedMail;
use App\Mail\PaypalClaimStudentAckMail;
use App\Mail\StudentWelcomeMail;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\StudentDiscount;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaypalClaimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        MarketingSetting::flushCached();

        // Фича по умолчанию выключена — включаем на время тестов.
        config([
            'services.paypal.enabled' => true,
            'services.paypal.me_link' => 'https://www.paypal.com/paypalme/school',
            'services.admin.email' => 'admin@example.test',
        ]);
    }

    private function blockTariff(): Tariff
    {
        $course = Course::factory()->create();

        return Tariff::factory()->for($course)->block(2)->create(['price' => 4800]);
    }

    /** @test */
    public function disabled_feature_returns_404(): void
    {
        config(['services.paypal.enabled' => false]);
        $tariff = $this->blockTariff();

        $this->get(route('paypal.claim.show', $tariff))->assertNotFound();
    }

    /** @test */
    public function enabled_form_renders(): void
    {
        $tariff = $this->blockTariff();

        // MG 23-08-2026: это УВЕДОМЛЕНИЕ об оплате, не сама оплата.
        $this->get(route('paypal.claim.show', $tariff))
            ->assertOk()
            ->assertSee('Уведомление об оплате через PayPal')
            ->assertSee('Сообщите нам об оплате')
            ->assertSee('С какого PayPal платили')
            ->assertSee('Дата оплаты')
            ->assertSee('Комиссию PayPal за перевод оплачивает отправитель');
    }

    /** @test */
    public function block_tariff_shows_foreign_price_from_config(): void
    {
        // MG 23-08-2026: рублевую цену на форме не показываем; в PayPal платят
        // EUR (предпочтительно) / USD по валютному прайсу из конфига.
        config(['services.paypal.foreign_block_prices' => [1 => ['eur' => 90, 'usd' => 105]]]);
        $tariff = $this->blockTariff(); // course id = 1 на чистой базе

        $this->get(route('paypal.claim.show', $tariff))
            ->assertOk()
            ->assertSee('90 €', false)
            ->assertSee('(предпочтительно)', false)
            ->assertSee('105 $', false)
            ->assertDontSee('8 000 ₽')
            ->assertDontSee(number_format((float) $tariff->price, 0, '.', ' ').' ₽', false);
    }

    /** @test */
    public function fixed_price_list_flag_off_keeps_legacy_config_behavior_for_full_tariff(): void
    {
        // H3821: full ('весь курс') tariffs never had a foreign price shown
        // pre-fix — the legacy config path only covers 'block'. Flag OFF must
        // reproduce that exactly (prod-inert merge).
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->create(['type' => 'full', 'price' => 12000]);

        $this->get(route('paypal.claim.show', $tariff))
            ->assertOk()
            ->assertDontSee('Стоимость блока', false);
    }

    /** @test */
    public function fixed_price_list_flag_on_shows_computed_markup_price(): void
    {
        config(['features.paypal_fixed_price_list' => true]);
        Config::set('services.exchangerate.key', 'test-key');
        Http::fake([
            'api.exchangerate.host/*' => Http::response([
                'success' => true,
                'source' => 'USD',
                'quotes' => ['USDRUB' => 100.0, 'USDEUR' => 1.0],
            ]),
        ]);

        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->create(['type' => 'full', 'price' => 12000]);

        // 12000 / 100 * 1.08 = 129.6
        $this->get(route('paypal.claim.show', $tariff))
            ->assertOk()
            ->assertSee('129.6', false);
    }

    /** @test */
    public function fixed_price_list_flag_on_excludes_markup_for_active_student_discount(): void
    {
        config(['features.paypal_fixed_price_list' => true]);
        Config::set('services.exchangerate.key', 'test-key');
        Http::fake([
            'api.exchangerate.host/*' => Http::response([
                'success' => true,
                'source' => 'USD',
                'quotes' => ['USDRUB' => 100.0, 'USDEUR' => 1.0],
            ]),
        ]);

        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->create(['type' => 'full', 'price' => 12000]);
        $user = User::factory()->create();
        StudentDiscount::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'type' => StudentDiscount::TYPE_PERCENT,
            'value' => 50,
            'is_active' => true,
        ]);

        // Discounted RUB = 12000 * 0.5 = 6000; pure conversion, no 1.08 markup → 60.
        $this->actingAs($user)->get(route('paypal.claim.show', $tariff))
            ->assertOk()
            ->assertSee('60 €', false)
            ->assertDontSee('129.6', false);
    }

    /** @test */
    public function claim_page_includes_paste_autofill_control(): void
    {
        $tariff = $this->blockTariff();

        // H2215: step 2 exposes paste-to-fill UI + the pure client parser script.
        $this->get(route('paypal.claim.show', $tariff))
            ->assertOk()
            ->assertSee('Вставить детали из PayPal', false)
            ->assertSee('id="paypal-paste-input"', false)
            ->assertSee('id="paypal-paste-parse"', false)
            ->assertSee('Заполнить из вставки', false)
            ->assertSee('paypal-claim-paste.js', false)
            ->assertSee('id="paypal-claim-form"', false);
    }

    /** @test */
    public function claim_page_states_timeframe_and_next_steps(): void
    {
        $tariff = $this->blockTariff();

        // H1292: страница обязана давать тайминг сверки, шаги «что будет дальше»
        // и снятие страха двойного списания (общая строка 2 волны revenue-copy).
        $this->get(route('paypal.claim.show', $tariff))
            ->assertOk()
            ->assertSee('обычно')
            ->assertSee('одного рабочего дня')
            ->assertSee('Что будет дальше')
            ->assertSee('не платите повторно');
    }

    /** @test */
    public function checkout_cta_partial_states_timeframe_and_reason(): void
    {
        $tariff = $this->blockTariff();

        // H1292: CTA на чекауте — ожидание (тайминг) + причина ручной сверки.
        $html = view('partials.paypal-cta', ['tariff' => $tariff])->render();

        $this->assertStringContainsString('одного рабочего дня', $html);
        $this->assertStringContainsString('сверяем вручную', $html);
    }

    /** @test */
    public function guest_claim_creates_pending_paypal_payment_without_access(): void
    {
        $tariff = $this->blockTariff();

        $response = $this->post(route('paypal.claim.store', $tariff), [
            'name' => 'Джон',
            'email' => 'john@example.test',
            'foreign_amount' => 50,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-07-30',
            'foreign_currency' => 'USD',
            'paypal_txn' => '1AB23456CD789012E',
            'proof' => UploadedFile::fake()->image('receipt.png'),
            'comment' => 'Оплатил из США',
        ]);

        $response->assertRedirect(route('paypal.claim.show', $tariff));
        $response->assertSessionHas('success');

        $payment = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest()->firstOrFail();

        $this->assertSame('pending', $payment->status);
        $this->assertSame('block_2', $payment->tariff);
        $this->assertSame(2, $payment->start_block);
        $this->assertSame(4800.0, (float) $payment->amount, 'Рублёвый номинал = цена тарифа.');
        $this->assertSame(50.0, (float) $payment->foreign_amount);
        $this->assertSame('USD', $payment->foreign_currency);
        $this->assertSame('payer@example.com', $payment->claimMeta('paypal_payer'));
        $this->assertSame('2026-07-30', $payment->claimMeta('paid_on'));
        $this->assertNotNull($payment->proof_path);
        Storage::disk('local')->assertExists($payment->proof_path);

        // pending НЕ открывает доступ.
        $this->assertSame(0, $payment->user->groups()->count());
        $this->assertFalse($payment->user->courses()->where('courses.id', $tariff->course_id)->exists());

        // Уведомление админу отправлено.
        Mail::assertQueued(PaypalClaimReceivedMail::class);
    }

    /** @test */
    public function student_receives_acknowledgement_mail_on_claim(): void
    {
        $tariff = $this->blockTariff();

        $this->post(route('paypal.claim.store', $tariff), [
            'name' => 'Джон',
            'email' => 'john@example.test',
            'foreign_amount' => 50,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-07-30',
            'foreign_currency' => 'USD',
            'paypal_txn' => 'TX1',
        ]);

        // H1292: студент получает подтверждение приёма заявки…
        Mail::assertQueued(PaypalClaimStudentAckMail::class, fn ($mail) => $mail->hasTo('john@example.test'));

        // …а админское письмо продолжает уходить без изменений.
        Mail::assertQueued(PaypalClaimReceivedMail::class, fn ($mail) => $mail->hasTo('admin@example.test'));
    }

    /** @test */
    public function logged_in_student_receives_acknowledgement_too(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create(['email' => 'student@example.test']);

        $this->actingAs($user)->post(route('paypal.claim.store', $tariff), [
            'foreign_amount' => 40,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-07-30',
            'foreign_currency' => 'EUR',
            'paypal_txn' => 'TX-EUR',
        ]);

        Mail::assertQueued(PaypalClaimStudentAckMail::class, fn ($mail) => $mail->hasTo('student@example.test'));
    }

    /** @test */
    public function student_ack_mail_renders_expectations(): void
    {
        $tariff = $this->blockTariff();

        $this->post(route('paypal.claim.store', $tariff), [
            'name' => 'Джон',
            'email' => 'john@example.test',
            'foreign_amount' => 50,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-07-30',
            'foreign_currency' => 'USD',
            'paypal_txn' => 'TX1',
        ]);

        $payment = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest()->firstOrFail();

        $html = (new PaypalClaimStudentAckMail($payment))->render();

        // Маркер школы, тайминг сверки, снятие страха двойного списания,
        // заявленная валютная сумма и канал поддержки.
        $this->assertStringContainsString('Намасте', $html);
        $this->assertStringContainsString('одного рабочего дня', $html);
        $this->assertStringContainsString('не платите повторно', $html);
        $this->assertStringContainsString('50.00 $', $html);
        $this->assertStringContainsString('t.me/rusamskrtam', $html);

        // Контракт голоса: без ё в новой копии (правило D13; «всё» здесь нет).
        $this->assertStringNotContainsString('ё', $html);
    }

    /** @test */
    public function claim_without_txn_or_proof_is_accepted(): void
    {
        // H2017: personal PayPal — reconciliation keys are from + date + amount;
        // txn/proof are optional helpers.
        $tariff = $this->blockTariff();

        $this->post(route('paypal.claim.store', $tariff), [
            'name' => 'Джон',
            'email' => 'john@example.test',
            'foreign_amount' => 50,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-07-30',
            'foreign_currency' => 'USD',
        ])->assertRedirect(route('paypal.claim.show', $tariff));

        $this->assertSame(1, Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->count());
    }

    /** @test */
    public function requires_paypal_payer_and_paid_on(): void
    {
        $tariff = $this->blockTariff();

        $this->post(route('paypal.claim.store', $tariff), [
            'name' => 'Джон',
            'email' => 'john@example.test',
            'foreign_amount' => 50,
            'foreign_currency' => 'USD',
        ])->assertSessionHasErrors(['paypal_payer', 'paid_on']);

        $this->assertSame(0, Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->count());
    }

    /** @test */
    public function guest_with_existing_email_is_rejected(): void
    {
        $tariff = $this->blockTariff();
        User::factory()->create(['email' => 'taken@example.test']);

        $this->post(route('paypal.claim.store', $tariff), [
            'name' => 'Джон',
            'email' => 'taken@example.test',
            'foreign_amount' => 50,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-07-30',
            'foreign_currency' => 'USD',
            'paypal_txn' => 'TX1',
        ])->assertSessionHasErrors('email');

        $this->assertSame(0, Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->count());
    }

    /** @test */
    public function admin_confirmation_grants_access_and_sends_welcome(): void
    {
        $tariff = $this->blockTariff();

        $this->post(route('paypal.claim.store', $tariff), [
            'name' => 'Джон',
            'email' => 'john@example.test',
            'foreign_amount' => 50,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-07-30',
            'foreign_currency' => 'USD',
            'paypal_txn' => 'TX1',
        ]);

        $payment = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest()->firstOrFail();

        // Админ сверил и подтвердил (как кнопка «Подтвердить PayPal» в Filament).
        $payment->update(['status' => 'paid']);

        // Доступ открылся штатной логикой Payment::booted() → запись на курс.
        $this->assertTrue(
            $payment->user->fresh()->courses()->where('courses.id', $tariff->course_id)->exists(),
            'После подтверждения студент должен быть записан на курс.'
        );

        // Первая оплата → welcome-письмо с паролем.
        Mail::assertQueued(StudentWelcomeMail::class, fn ($mail) => $mail->hasTo('john@example.test'));
    }

    /** @test */
    public function logged_in_student_does_not_need_name_email(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('paypal.claim.store', $tariff), [
            'foreign_amount' => 40,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-07-30',
            'foreign_currency' => 'EUR',
            'paypal_txn' => 'TX-EUR',
        ])->assertRedirect(route('paypal.claim.show', $tariff));

        $payment = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest()->firstOrFail();
        $this->assertSame($user->id, $payment->user_id);
        $this->assertSame('EUR', $payment->foreign_currency);
    }

    /** @test */
    public function logged_in_existing_student_claim_is_paid_immediately_with_access(): void
    {
        // Ruling 22-08-2026: свой ученик (вошел в кабинет) получает доступ
        // сразу — заявка создается paid, сверка делается после и выборочно.
        $tariff = $this->blockTariff();
        $user = User::factory()->create(['email' => 'student@example.test']);

        $response = $this->actingAs($user)->post(route('paypal.claim.store', $tariff), [
            'foreign_amount' => 40,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-08-22',
            'foreign_currency' => 'EUR',
            'paypal_txn' => 'TX-TRUST',
        ]);

        $response->assertRedirect(route('paypal.claim.show', $tariff));
        $response->assertSessionHas('success');

        $payment = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest()->firstOrFail();

        $this->assertSame('paid', $payment->status);
        $this->assertTrue($payment->isAutoTrustedPaypal());
        $this->assertNotNull($payment->claimMeta('trusted_at'));
        $this->assertNull($payment->claimMeta('verified_at'));

        // Штатный конвейер отработал без ручного шага: студент записан на курс.
        $this->assertTrue(
            $payment->user->fresh()->courses()->where('courses.id', $tariff->course_id)->exists(),
            'Авто-доверенная заявка должна открыть доступ немедленно.'
        );

        // Подтверждение студенту и сигнал кураторам уходят в обеих ветках.
        Mail::assertQueued(PaypalClaimStudentAckMail::class, fn ($mail) => $mail->hasTo('student@example.test'));
        Mail::assertQueued(PaypalClaimReceivedMail::class);
    }

    /** @test */
    public function trusted_claim_sits_in_unverified_queue_until_spot_check(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('paypal.claim.store', $tariff), [
            'foreign_amount' => 40,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-08-22',
            'foreign_currency' => 'EUR',
        ]);

        $payment = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest()->firstOrFail();

        // В очереди выборочной сверки, пока «Сверка пройдена» не нажата.
        $this->assertSame(1, Payment::query()->paypalUnverified()->whereKey($payment->getKey())->count());

        $payment->markPaypalVerified();

        $this->assertSame(0, Payment::query()->paypalUnverified()->whereKey($payment->getKey())->count());
        $this->assertNotNull($payment->fresh()->claimMeta('verified_at'));
    }

    /** @test */
    public function rejecting_trusted_claim_revokes_group_access(): void
    {
        // Курс с группой: grantAccess выдает группу, откат canceled ее снимает.
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $group->courses()->attach($course);
        $tariff = Tariff::factory()->for($course)->block(2)->create(['price' => 4800]);
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('paypal.claim.store', $tariff), [
            'foreign_amount' => 40,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-08-22',
            'foreign_currency' => 'EUR',
        ]);

        $payment = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest()->firstOrFail();
        $this->assertSame(1, $payment->user->groups()->count(), 'Доступ выдан сразу.');

        // «Нет платежа — отменить»: canceled на paid запускает штатный откат.
        $payment->update(['status' => 'canceled']);

        $this->assertSame(0, $payment->user->fresh()->groups()->count(), 'Отзыв доступа должен снять группу.');
    }

    /** @test */
    public function trust_flag_disabled_keeps_manual_pending_flow(): void
    {
        config(['services.paypal.trust_existing_students' => false]);
        $tariff = $this->blockTariff();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('paypal.claim.store', $tariff), [
            'foreign_amount' => 40,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-07-30',
            'foreign_currency' => 'EUR',
        ]);

        $payment = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest()->firstOrFail();

        $this->assertSame('pending', $payment->status);
        $this->assertFalse($payment->isAutoTrustedPaypal());
        $this->assertSame(0, $payment->user->groups()->count());
        $this->assertSame(0, Payment::query()->paypalUnverified()->whereKey($payment->getKey())->count());
    }

    /** @test */
    public function student_ack_mail_renders_trusted_variant(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('paypal.claim.store', $tariff), [
            'foreign_amount' => 40,
            'paypal_payer' => 'payer@example.com',
            'paid_on' => '2026-08-22',
            'foreign_currency' => 'EUR',
        ]);

        $payment = Payment::query()->where('provider', Payment::PROVIDER_PAYPAL)->latest()->firstOrFail();
        $mail = new PaypalClaimStudentAckMail($payment);

        $html = $mail->render();

        // Своя ветка копии: доступ уже открыт, ожидания сверки нет.
        $this->assertStringContainsString('открыт сразу', $html);
        $this->assertStringContainsString('без ожидания', $html);
        $this->assertStringNotContainsString('одного рабочего дня', $html);
        $this->assertStringContainsString('40.00 €', $html);
        $this->assertSame('Заявка получена — доступ открыт', $mail->envelope()->subject);

        // Контракт голоса: без ё в новой копии (правило D13).
        $this->assertStringNotContainsString('ё', $html);
    }
}
