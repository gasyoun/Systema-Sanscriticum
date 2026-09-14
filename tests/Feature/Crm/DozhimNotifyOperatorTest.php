<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Course;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * NOBORING дожим, операторская сводка (решение MG 24-08-2026): будни 10:00 —
 * Telegram-список недожатых open Deal владельцу очереди. Гейт
 * dozhim_operator_notify; пустая очередь молчит; получатель — chat_id из
 * конфига либо User роли manager с telegram_id.
 */
class DozhimNotifyOperatorTest extends TestCase
{
    use RefreshDatabase;

    private function seedDealAgedHours(int $hours): Deal
    {
        $stage = DealStage::first() ?? DealStage::factory()->create();
        $course = Course::factory()->create(['title' => 'Санскрит с нуля']);
        $user = User::factory()->create(['name' => 'Тест Клиент']);

        $deal = Deal::factory()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'stage_id' => $stage->id,
            'closed_at' => null,
            'amount' => 15000,
            'currency' => 'RUB',
        ]);

        $deal->forceFill(['created_at' => now()->subHours($hours)])->save();

        return $deal;
    }

    private function seedManagerWithTelegram(string $tgId = '777'): User
    {
        return User::factory()->create([
            'role' => 'manager',
            'telegram_id' => $tgId,
            'email' => 'operator@example.com',
        ]);
    }

    /** @test */
    public function sends_nothing_when_flag_off(): void
    {
        config(['features.dozhim_operator_notify' => false]);
        $this->seedDealAgedHours(48);

        Http::fake();

        $this->artisan('dozhim:notify-operator')->assertSuccessful();

        Http::assertNothingSent();
    }

    /** @test */
    public function silent_when_queue_empty(): void
    {
        config(['features.dozhim_operator_notify' => true, 'services.dozhim_operator_tg_chat_id' => '100500']);
        config(['dozhim.unpaid_deal_hours' => 24]);

        Http::fake();

        $this->artisan('dozhim:notify-operator')->assertSuccessful();

        Http::assertNothingSent();
    }

    /** @test */
    public function sends_digest_to_configured_chat_id_with_deal_details(): void
    {
        config([
            'features.dozhim_operator_notify' => true,
            'services.dozhim_operator_tg_chat_id' => '100500',
            'services.telegram.bot_token' => 'test-token',
            'dozhim.unpaid_deal_hours' => 24,
        ]);
        $this->seedDealAgedHours(48);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->artisan('dozhim:notify-operator')->assertSuccessful();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains((string) $request->url(), '/sendMessage')
                && $body['chat_id'] === '100500'
                && str_contains($body['text'], 'недожатых сделок — 1')
                && str_contains($body['text'], 'Тест Клиент')
                && str_contains($body['text'], 'Санскрит с нуля')
                && str_contains($body['text'], '15 000')
                && str_contains($body['text'], '/admin/work-queue');
        });
    }

    /** @test */
    public function falls_back_to_manager_user_with_telegram_id(): void
    {
        config([
            'features.dozhim_operator_notify' => true,
            'services.dozhim_operator_tg_chat_id' => null,
            'dozhim.unpaid_deal_hours' => 24,
        ]);
        $this->seedManagerWithTelegram('888');
        $this->seedDealAgedHours(48);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->artisan('dozhim:notify-operator')->assertSuccessful();

        Http::assertSent(function ($request) {
            return str_contains((string) $request->url(), '/sendMessage')
                && ($request->data()['chat_id'] ?? null) == '888';
        });
    }

    /** @test */
    public function force_bypasses_flag_for_delivery_check(): void
    {
        config([
            'features.dozhim_operator_notify' => false,
            'services.dozhim_operator_tg_chat_id' => '100500',
            'services.telegram.bot_token' => 'test-token',
            'dozhim.unpaid_deal_hours' => 24,
        ]);
        $this->seedDealAgedHours(48);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $this->artisan('dozhim:notify-operator', ['--force' => true])->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/sendMessage'));
    }

    /** @test */
    public function telegram_rejection_is_a_failure_not_a_silent_ok(): void
    {
        config([
            'features.dozhim_operator_notify' => true,
            'services.dozhim_operator_tg_chat_id' => '100500',
            'services.telegram.bot_token' => 'test-token',
            'dozhim.unpaid_deal_hours' => 24,
        ]);
        $this->seedDealAgedHours(48);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400),
        ]);

        $this->artisan('dozhim:notify-operator')->assertFailed();
    }
}
