<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InstituteDonation;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ViewErrorBag;
use Tests\Feature\Webhooks\TochkaWebhookTest;
use Tests\TestCase;

/**
 * N2/N3 Института: онлайн-приём пожертвований (/mecenaty) и реестр благодарностей.
 * Контур за флагом INSTITUTE_DONATE_ONLINE (default OFF); успех вебхука меняет
 * только строку institute_donations — ни Payment, ни доступов, ни писем.
 */
final class InstituteDonationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.tochka.url' => 'https://tochka-api.test',
            'services.tochka.token' => 'test-token',
            'services.tochka.customer_code' => 'cc',
            'services.tochka.webhook_public_key' => TochkaWebhookTest::TEST_JWK,
        ]);
    }

    private function enableFlag(): void
    {
        config(['features.institute_donate_online' => true]);
    }

    private function sign(array $payload): string
    {
        return JWT::encode($payload, TochkaWebhookTest::TEST_PRIVATE_PEM, 'RS256');
    }

    private function postJwt(string $jwt)
    {
        return $this->call('POST', '/api/webhooks/tochka', [], [], [], ['CONTENT_TYPE' => 'application/jwt'], $jwt);
    }

    /** @test */
    public function donate_route_is_404_while_flag_off(): void
    {
        $this->post(route('institute.donate'), ['amount' => 500, 'email' => 'a@b.ru'])
            ->assertNotFound();
    }

    /** @test */
    public function gratitude_page_renders_empty_while_flag_off(): void
    {
        $this->get(route('institute.gratitude'))
            ->assertOk()
            ->assertSee('Пока список пуст');
    }

    /** @test */
    public function donate_validates_amount_email_and_honeypot(): void
    {
        $this->enableFlag();

        $this->from('/mecenaty')->post(route('institute.donate'), [
            'amount' => 10, // ниже минимума
            'email' => 'not-an-email',
        ])->assertRedirect('/mecenaty')->assertSessionHasErrors(['amount', 'email']);

        $this->post(route('institute.donate'), [
            'amount' => 500,
            'email' => 'a@b.ru',
            'website' => 'spam',
        ])->assertSessionHasErrors(['website']);

        $this->assertDatabaseCount('institute_donations', 0);
    }

    /** @test */
    public function donate_creates_pending_row_and_redirects_to_bank_link(): void
    {
        $this->enableFlag();
        Http::fake([
            'tochka-api.test/*' => Http::response([
                'Data' => ['paymentLinkId' => 'pl_123', 'paymentLink' => 'https://pay.test/link'],
            ]),
        ]);

        $response = $this->post(route('institute.donate'), [
            'amount' => 1500,
            'email' => 'donor@test.ru',
            'name' => 'Иван',
            'publish_name' => '1',
            'show_amount' => '1',
        ]);

        $response->assertRedirect('https://pay.test/link');

        $this->assertDatabaseHas('institute_donations', [
            'amount' => 1500,
            'status' => InstituteDonation::STATUS_PENDING,
            'email' => 'donor@test.ru',
            'publish_name' => true,
            'show_amount' => true,
            'tochka_link_id' => 'pl_123',
        ]);
    }

    /** @test */
    public function bank_failure_marks_donation_failed(): void
    {
        $this->enableFlag();
        Http::fake(['tochka-api.test/*' => Http::response(['error' => 'x'], 500)]);

        $this->from('/mecenaty')->post(route('institute.donate'), [
            'amount' => 500,
            'email' => 'donor@test.ru',
        ])->assertRedirect('/mecenaty')->assertSessionHas('error');

        $this->assertDatabaseHas('institute_donations', [
            'status' => InstituteDonation::STATUS_FAILED,
        ]);
    }

    /** @test */
    public function approved_webhook_marks_donation_paid_and_is_idempotent(): void
    {
        $this->enableFlag();
        Http::fake(['tochka-api.test/*' => Http::response([
            'Data' => ['paymentLinkId' => 'pl_1', 'paymentLink' => 'https://pay.test/l'],
        ])]);
        $this->post(route('institute.donate'), ['amount' => 700, 'email' => 'd@t.ru']);
        $donation = InstituteDonation::query()->sole();

        $jwt = $this->sign([
            'purpose' => "Добровольное пожертвование Институту исследования санскрита №D{$donation->id}",
            'status' => 'APPROVED',
            'amount' => 700,
        ]);

        $this->postJwt($jwt)->assertOk();
        $this->assertSame(InstituteDonation::STATUS_PAID, $donation->fresh()->status);
        $this->assertNotNull($donation->fresh()->paid_at);

        // Повтор доставки — no-op (paid_at не двигается).
        $firstPaidAt = $donation->fresh()->paid_at;
        $this->postJwt($jwt)->assertOk();
        $this->assertTrue($firstPaidAt->equalTo($donation->fresh()->paid_at));
    }

    /** @test */
    public function webhook_amount_mismatch_does_not_mark_paid(): void
    {
        $this->enableFlag();
        Http::fake(['tochka-api.test/*' => Http::response([
            'Data' => ['paymentLinkId' => 'pl_1', 'paymentLink' => 'https://pay.test/l'],
        ])]);
        $this->post(route('institute.donate'), ['amount' => 700, 'email' => 'd@t.ru']);
        $donation = InstituteDonation::query()->sole();

        $jwt = $this->sign([
            'purpose' => "Добровольное пожертвование Институту исследования санскрита №D{$donation->id}",
            'status' => 'APPROVED',
            'amount' => 9.99,
        ]);

        $this->postJwt($jwt)->assertOk();
        $this->assertSame(InstituteDonation::STATUS_PENDING, $donation->fresh()->status);
    }

    /** @test */
    public function donation_webhook_never_touches_payments_path(): void
    {
        $this->enableFlag();
        Http::fake(['tochka-api.test/*' => Http::response([
            'Data' => ['paymentLinkId' => 'pl_1', 'paymentLink' => 'https://pay.test/l'],
        ])]);
        $this->post(route('institute.donate'), ['amount' => 300, 'email' => 'd@t.ru']);
        $donation = InstituteDonation::query()->sole();

        $jwt = $this->sign([
            // «Заказ №{id доната}» не должен резолвиться в реальный платёж с тем же id:
            // маркер «пожертвование №D» уводит ветку раньше разбора заказа.
            'purpose' => "Добровольное пожертвование №D{$donation->id}",
            'status' => 'APPROVED',
        ]);
        $this->postJwt($jwt)->assertOk();
        $this->assertSame(InstituteDonation::STATUS_PAID, $donation->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    /** @test */
    public function registry_shows_consented_names_and_amount_only_on_request(): void
    {
        InstituteDonation::create([
            'uuid' => 'u1', 'amount' => 5000, 'status' => 'paid', 'paid_at' => now(),
            'email' => 'a@t.ru', 'donor_name' => 'Публичный', 'publish_name' => true, 'show_amount' => true,
        ]);
        InstituteDonation::create([
            'uuid' => 'u2', 'amount' => 1000, 'status' => 'paid', 'paid_at' => now()->subDay(),
            'email' => 'b@t.ru', 'donor_name' => 'Скромный', 'publish_name' => true, 'show_amount' => false,
        ]);
        InstituteDonation::create([
            'uuid' => 'u3', 'amount' => 9999, 'status' => 'paid', 'paid_at' => now(),
            'email' => 'c@t.ru', 'donor_name' => 'Тайный', 'publish_name' => false, 'show_amount' => false,
        ]);
        InstituteDonation::create([
            'uuid' => 'u4', 'amount' => 400, 'status' => 'pending',
            'email' => 'e@t.ru', 'donor_name' => 'Неоплативший', 'publish_name' => true, 'show_amount' => true,
        ]);

        $this->enableFlag();

        // Прямой рендер вне HTTP-контекста: шарим error bag для partial'ов лейаута.
        view()->share('errors', new ViewErrorBag);

        $html = view('institute.gratitude', [
            'donations' => InstituteDonation::query()->publicRegistry()->get(),
        ])->render();

        // Имя и сумма — у согласившегося с просьбой показать сумму.
        $this->assertStringContainsString('Публичный', $html);
        $this->assertStringContainsString('5 000', $html);
        // Имя без суммы — у согласившегося, не просившего показывать сумму.
        $this->assertStringContainsString('Скромный', $html);
        $this->assertStringNotContainsString('— 1 000', $html);
        // Ни имени, ни суммы — у несогласившегося и неоплаченного.
        $this->assertStringNotContainsString('Тайный', $html);
        $this->assertStringNotContainsString('9 999', $html);
        $this->assertStringNotContainsString('Неоплативший', $html);
        $this->assertStringNotContainsString('— 400', $html);
    }
}
