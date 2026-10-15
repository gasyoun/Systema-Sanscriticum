<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\MarketingSettingResource;
use App\Filament\Resources\MarketingSettingResource\Pages\EditMarketingSetting;
use App\Filament\Resources\UserResource;
use App\Models\MarketingSetting;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Инцидент 15-10-2026: браузер вписывал сохранённый пароль сайта в поле
 * «Bot Token» глобальных настроек — после сохранения @samskrte_bot молчал.
 * Секреты не автозаполняются, а мусор вместо токена не сохраняется.
 */
class SecretFieldsNoAutofillTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw0';

    private MarketingSetting $settings;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
        $this->settings = MarketingSetting::create([
            'tg_bot_username' => 'samskrte_bot',
            'tg_bot_token' => self::TOKEN,
            'tg_webhook_secret' => 'secret_ABC-123',
        ]);
    }

    private function edit(): Testable
    {
        return Livewire::test(EditMarketingSetting::class, ['record' => $this->settings->getRouteKey()]);
    }

    public function test_browser_password_is_not_saved_as_bot_token(): void
    {
        $this->edit()
            ->fillForm(['tg_bot_token' => 'НовыйПароль123'])
            ->call('save')
            ->assertHasFormErrors(['tg_bot_token' => 'regex']);

        $this->assertSame(self::TOKEN, $this->settings->fresh()->tg_bot_token);
    }

    public function test_real_token_and_empty_token_are_saved(): void
    {
        $other = '987654321:AAF-other_token_with_enough_symbols_x';

        $this->edit()->fillForm(['tg_bot_token' => $other])->call('save')->assertHasNoFormErrors();
        $this->assertSame($other, $this->settings->fresh()->tg_bot_token);

        $this->edit()->fillForm(['tg_bot_token' => null])->call('save')->assertHasNoFormErrors();
        $this->assertNull($this->settings->fresh()->tg_bot_token);
    }

    public function test_webhook_secret_rejects_symbols_telegram_does_not_accept(): void
    {
        $this->edit()
            ->fillForm(['tg_webhook_secret' => 'Пароль 123'])
            ->call('save')
            ->assertHasFormErrors(['tg_webhook_secret' => 'regex']);

        $this->assertSame('secret_ABC-123', $this->settings->fresh()->tg_webhook_secret);
    }

    public function test_secret_inputs_tell_the_browser_not_to_autofill(): void
    {
        $html = $this->get(MarketingSettingResource::getUrl('edit', ['record' => $this->settings]))
            ->assertOk()
            ->getContent();

        // 8 секретов: токены и webhook-секреты Telegram, VK, Max, @zapisi_ORSbot.
        $this->assertSame(8, substr_count($html, 'autocomplete="new-password"'));
        $this->assertStringContainsString('data-1p-ignore="true"', $html);
        $this->assertStringContainsString('autocomplete="off"', $html);
    }

    public function test_student_password_field_is_not_autofilled(): void
    {
        $student = User::factory()->create(['role' => Roles::STUDENT]);

        $this->get(UserResource::getUrl('edit', ['record' => $student]))
            ->assertOk()
            ->assertSee('autocomplete="new-password"', false);
    }
}
