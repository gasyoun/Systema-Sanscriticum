<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H1290 — запрос «разбить оплату на части» с чекаута (ruling D6).
 *
 * Машинно-проверяемый пол лейна: сабмит запроса создаёт НОЛЬ строк в
 * payment_promises (и вообще ничего в деньгах), уведомляет кураторов и
 * подтверждает студенту, что запрос принят.
 */
class InstallmentRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.telegram.curators_chat_id' => '-1001234567890']);
    }

    private function blockTariff(): Tariff
    {
        $course = Course::factory()->create();

        return Tariff::factory()->for($course)->block(2)->create(['price' => 4800]);
    }

    /** @test */
    public function checkout_page_shows_the_installments_ask(): void
    {
        $tariff = $this->blockTariff();

        $this->get(route('checkout.show', $tariff))
            ->assertOk()
            ->assertSee('Нужно разбить оплату на части?')
            ->assertSee(route('checkout.installments', $tariff));
    }

    /** @test */
    public function ask_is_hidden_when_curators_chat_is_not_configured(): void
    {
        config(['services.telegram.curators_chat_id' => null]);
        $tariff = $this->blockTariff();

        // Без кураторского чата запрос ушёл бы в пустоту — точка входа не рендерится.
        $this->get(route('checkout.show', $tariff))
            ->assertOk()
            ->assertDontSee('Нужно разбить оплату на части?');
    }

    /** @test */
    public function student_request_notifies_curators_and_creates_no_promise(): void
    {
        $tariff = $this->blockTariff();
        $user = User::factory()->create(['name' => 'Арджуна Тестов']);

        $response = $this->actingAs($user)->post(route('checkout.installments', $tariff), [
            'comment' => 'Удобнее платить со стипендии',
        ]);

        $response->assertRedirect(route('checkout.show', $tariff).'#installments');
        $response->assertSessionHas('installments_requested');

        // Пол лейна: ноль строк в таблице обещаний, ноль платежей, ноль планов.
        $this->assertSame(0, PaymentPromise::query()->count());
        $this->assertSame(0, Payment::query()->count());

        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
        Queue::assertPushed(SendTelegramChatMessageJob::class, function (SendTelegramChatMessageJob $job) {
            return $job->chatId === '-1001234567890'
                && str_contains($job->text, 'Запрос оплаты по частям')
                && str_contains($job->text, 'Арджуна Тестов')
                && str_contains($job->text, 'Удобнее платить со стипендии')
                && str_contains($job->text, 'План не создан');
        });
    }

    /** @test */
    public function guest_request_requires_contact(): void
    {
        $tariff = $this->blockTariff();

        $this->post(route('checkout.installments', $tariff), [
            'comment' => 'Хочу по частям',
        ])->assertSessionHasErrorsIn('installments', ['contact']);

        $this->assertSame(0, PaymentPromise::query()->count());
        Queue::assertNotPushed(SendTelegramChatMessageJob::class);
    }

    /** @test */
    public function guest_request_with_contact_notifies_curators_without_creating_a_user(): void
    {
        $tariff = $this->blockTariff();
        $usersBefore = User::query()->count();

        $response = $this->post(route('checkout.installments', $tariff), [
            'name' => 'Иван',
            'contact' => '@ivan_tg',
        ]);

        $response->assertRedirect(route('checkout.show', $tariff).'#installments');
        $response->assertSessionHas('installments_requested');

        // Запрос — не регистрация и не платёж: никаких новых строк нигде.
        $this->assertSame($usersBefore, User::query()->count());
        $this->assertSame(0, PaymentPromise::query()->count());
        $this->assertSame(0, Payment::query()->count());

        Queue::assertPushed(SendTelegramChatMessageJob::class, function (SendTelegramChatMessageJob $job) {
            return str_contains($job->text, 'Иван')
                && str_contains($job->text, '@ivan_tg')
                && str_contains($job->text, 'не авторизован');
        });
    }

    /** @test */
    public function acknowledgement_renders_after_request(): void
    {
        $tariff = $this->blockTariff();

        $this->withSession(['installments_requested' => true])
            ->get(route('checkout.show', $tariff))
            ->assertOk()
            ->assertSee('Запрос отправлен')
            ->assertSee('обычно отвечаем в течение рабочего дня')
            ->assertSee('Написать в Telegram');
    }

    /** @test */
    public function inactive_tariff_returns_404(): void
    {
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->block(2)->create([
            'price' => 4800,
            'is_active' => false,
        ]);

        $this->post(route('checkout.installments', $tariff), [
            'contact' => '@ivan_tg',
        ])->assertNotFound();

        Queue::assertNotPushed(SendTelegramChatMessageJob::class);
    }
}
