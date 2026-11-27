<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Consent;
use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Согласия на чекауте (рулинг MG 24-11-2026).
 *
 * Было: под кнопкой оплаты строка «Нажимая на кнопку, вы принимаете публичную
 * оферту и подтверждаете, что ознакомлены с политикой конфиденциальности», а
 * галочка ПДн висела отдельно в карточке гостевых данных и на сервере была
 * необязательной (флаг CHECKOUT_PD_CONSENT_ENFORCE, дефолт OFF). Стало: три
 * отдельные галочки одним блоком НАД кнопкой — оферта и ПДн обязательные для
 * гостя и для студента, рассылка отдельная и по умолчанию снятая.
 *
 * Эти тесты держат именно контракт: (1) без галочек платёж не создаётся,
 * (2) имя полей — то, которое читает сервер, (3) согласия попадают в журнал
 * consents, (4) снятая галочка рассылки не отписывает подписанного.
 */
class CheckoutConsentBoxesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();

        // Точка не дёргается по-настоящему — отдаём фейковую платёжную ссылку.
        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/abc',
                    'paymentLinkId' => 'tochka_tx_001',
                ],
            ], 200),
        ]);
    }

    private function tariff(): Tariff
    {
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        return Tariff::factory()->for($course)->create(['price' => 5000]);
    }

    /** @param array<string,mixed> $overrides */
    private function guestPayload(Tariff $tariff, array $overrides = []): array
    {
        return array_merge([
            'tariff_id' => $tariff->id,
            'name' => 'Иван',
            'surname' => 'Иванов',
            'city' => 'Москва',
            'email' => 'consent@example.test',
            'oferta_accepted' => '1',
            'pd_consent' => '1',
        ], $overrides);
    }

    /** @test */
    public function guest_checkout_page_renders_the_three_boxes_instead_of_the_old_paragraph(): void
    {
        $tariff = $this->tariff();

        $response = $this->get(route('checkout.show', $tariff));

        $response->assertOk();
        $response->assertSee('data-testid="checkout-consents"', false);

        // Имена полей — ровно те, что читают ConsentRules/PaymentController.
        $response->assertSee('name="oferta_accepted"', false);
        $response->assertSee('name="pd_consent"', false);
        $response->assertSee('name="wants_announcements"', false);

        // Галочки лежат вне <form id="checkout-form"> (карточка кнопки идёт после
        // промокода со своей формой) — без атрибута form поля не уйдут на сервер.
        $response->assertSee('form="checkout-form"', false);

        // Оферта, согласие на ПДн и согласие на рассылку — все три документа.
        $response->assertSee(route('docs.show', 'oferta'), false);
        $response->assertSee(route('docs.show', 'soglasie-pd'), false);
        $response->assertSee(route('docs.show', 'soglasie-promo'), false);

        // Старая строка под кнопкой удалена целиком, а не продублирована.
        $response->assertDontSee('Нажимая на кнопку', false);
    }

    /** @test */
    public function guest_without_consents_cannot_pay_and_no_payment_is_created(): void
    {
        $tariff = $this->tariff();

        $this->post(route('payment.create'), $this->guestPayload($tariff, [
            'oferta_accepted' => null,
            'pd_consent' => null,
        ]))->assertSessionHasErrors(['oferta_accepted', 'pd_consent']);

        $this->assertSame(0, Payment::count());
        $this->assertDatabaseMissing('users', ['email' => 'consent@example.test']);
    }

    /** @test */
    public function oferta_alone_is_not_enough_without_pd_consent(): void
    {
        $tariff = $this->tariff();

        $this->post(route('payment.create'), $this->guestPayload($tariff, [
            'pd_consent' => null,
        ]))->assertSessionHasErrors('pd_consent');

        $this->assertSame(0, Payment::count());
    }

    /** @test */
    public function logged_in_student_must_tick_the_same_boxes(): void
    {
        $tariff = $this->tariff();
        $student = User::factory()->create();

        // Раньше галочки жили только в гостевой ветке формы, и студент мог
        // оплатить вообще без согласий: серверных правил для него не было.
        $this->actingAs($student)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertSessionHasErrors(['oferta_accepted', 'pd_consent']);

        $this->assertSame(0, Payment::count());
    }

    /** @test */
    public function subscribed_student_sees_where_to_unsubscribe_instead_of_a_second_checkbox(): void
    {
        $tariff = $this->tariff();
        $student = User::factory()->create(['wants_email_announcements' => true]);

        $response = $this->actingAs($student)->get(route('checkout.show', $tariff));

        $response->assertOk();
        $response->assertSee('name="oferta_accepted"', false);
        $response->assertSee('name="pd_consent"', false);
        // Переключатель подписки живёт в кабинете и умеет СНИМАТЬ согласие —
        // дублировать его здесь нечем, показываем дорогу.
        $response->assertDontSee('name="wants_announcements"', false);
        $response->assertSee('настройках кабинета', false);
    }

    /** @test */
    public function unsubscribed_student_still_gets_the_announcements_checkbox(): void
    {
        $tariff = $this->tariff();
        $student = User::factory()->create(['wants_email_announcements' => false]);

        $response = $this->actingAs($student)->get(route('checkout.show', $tariff));

        $response->assertOk();
        $response->assertSee('name="wants_announcements"', false);
    }

    /** @test */
    public function guest_consents_are_written_to_the_consent_journal(): void
    {
        $tariff = $this->tariff();

        $this->post(route('payment.create'), $this->guestPayload($tariff))
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        $user = User::where('email', 'consent@example.test')->firstOrFail();

        $this->assertDatabaseHas('consents', [
            'user_id' => $user->id,
            'type' => Consent::TYPE_PD,
            'action' => Consent::ACTION_GIVEN,
            'source' => 'checkout',
        ]);
    }

    /** @test */
    public function logged_in_student_consent_is_journaled_and_announcements_opt_in_is_saved(): void
    {
        $tariff = $this->tariff();
        $student = User::factory()->create(['wants_email_announcements' => false]);

        $this->actingAs($student)->post(route('payment.create'), [
            'tariff_id' => $tariff->id,
            'oferta_accepted' => '1',
            'pd_consent' => '1',
            'wants_announcements' => '1',
        ])->assertRedirect('https://pay.tochka.com/redirect/abc');

        $this->assertDatabaseHas('consents', [
            'user_id' => $student->id,
            'type' => Consent::TYPE_PD,
            'source' => 'checkout',
        ]);
        $this->assertDatabaseHas('consents', [
            'user_id' => $student->id,
            'type' => Consent::TYPE_PROMO,
            'source' => 'checkout',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'wants_email_announcements' => 1,
        ]);
    }

    /** @test */
    public function unticked_announcements_box_never_unsubscribes_a_subscribed_student(): void
    {
        $tariff = $this->tariff();
        $student = User::factory()->create(['wants_email_announcements' => true]);

        $this->actingAs($student)->post(route('payment.create'), [
            'tariff_id' => $tariff->id,
            'oferta_accepted' => '1',
            'pd_consent' => '1',
        ])->assertRedirect('https://pay.tochka.com/redirect/abc');

        // Отписка — отдельный явный жест в кабинете, не побочный эффект чекаута.
        $this->assertDatabaseHas('users', [
            'id' => $student->id,
            'wants_email_announcements' => 1,
        ]);
    }
}
