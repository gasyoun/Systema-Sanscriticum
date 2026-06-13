<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Http\Middleware\VerifyTelegramBotWebhook;
use App\Http\Middleware\VerifyVkBotWebhook;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Подпись легаси бот-вебхуков в режиме «enforce-if-configured»: пока секрет не
 * задан — пропускаем (без регрессии для прода), как только задан — fail-closed.
 * Тестируем middleware в изоляции на временных маршрутах.
 */
class BotWebhookSignatureTest extends TestCase
{
    private function tgRoute(): void
    {
        Route::post('/__test/tg-bot', fn () => 'ok')->middleware(VerifyTelegramBotWebhook::class);
    }

    private function vkRoute(): void
    {
        Route::post('/__test/vk-bot', fn () => 'ok')->middleware(VerifyVkBotWebhook::class);
    }

    /** @test */
    public function telegram_passes_through_when_secret_not_configured(): void
    {
        config(['services.telegram.bot_webhook_secret' => '']);
        $this->tgRoute();

        $this->post('/__test/tg-bot')->assertOk();
    }

    /** @test */
    public function telegram_rejects_missing_or_wrong_secret_when_configured(): void
    {
        config(['services.telegram.bot_webhook_secret' => 's3cret']);
        $this->tgRoute();

        $this->post('/__test/tg-bot')->assertStatus(403);
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'wrong')
            ->post('/__test/tg-bot')->assertStatus(403);
    }

    /** @test */
    public function telegram_accepts_matching_secret(): void
    {
        config(['services.telegram.bot_webhook_secret' => 's3cret']);
        $this->tgRoute();

        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 's3cret')
            ->post('/__test/tg-bot')->assertOk();
    }

    /** @test */
    public function vk_passes_through_when_secret_not_configured(): void
    {
        config(['services.vk.callback_secret' => '']);
        $this->vkRoute();

        $this->post('/__test/vk-bot', ['type' => 'message_new'])->assertOk();
    }

    /** @test */
    public function vk_rejects_wrong_secret_when_configured(): void
    {
        config(['services.vk.callback_secret' => 'vksecret']);
        $this->vkRoute();

        $this->post('/__test/vk-bot', ['type' => 'message_new', 'secret' => 'wrong'])
            ->assertStatus(403);
    }

    /** @test */
    public function vk_accepts_matching_secret(): void
    {
        config(['services.vk.callback_secret' => 'vksecret']);
        $this->vkRoute();

        $this->post('/__test/vk-bot', ['type' => 'message_new', 'secret' => 'vksecret'])
            ->assertOk();
    }

    /** @test */
    public function vk_confirmation_passes_without_secret_even_when_configured(): void
    {
        config(['services.vk.callback_secret' => 'vksecret']);
        $this->vkRoute();

        $this->post('/__test/vk-bot', ['type' => 'confirmation'])->assertOk();
    }
}
