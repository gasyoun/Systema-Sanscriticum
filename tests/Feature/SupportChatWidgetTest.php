<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MarketingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Виджет живого веб-чата на витрине (H536 Phase 4): плавающий пузырь посетителя
 * рендерится на главной, замкнут на публичные эндпоинты Phase 3 и отдаёт CSRF.
 */
class SupportChatWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_page_renders_the_chat_widget(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="scw-root"', false);
        $response->assertSee('id="scw-toggle"', false);
        $response->assertSee('Чат с поддержкой', false);
    }

    public function test_widget_is_wired_to_the_public_chat_endpoints(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-post-url="'.route('chat.message').'"', false);
        $response->assertSee('data-history-url="'.route('chat.history').'"', false);
    }

    public function test_widget_carries_a_csrf_token_for_the_post(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-csrf="', false);
        // Токен непустой (40-символьный csrf, не буквальная плейсхолдер-строка).
        $this->assertMatchesRegularExpression('/data-csrf="[A-Za-z0-9]{20,}"/', $response->getContent());
    }

    public function test_guest_sees_the_optional_name_field(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('id="scw-name"', false);
        // Для гостя поле имени видимо (не hidden-атрибут прямо на инпуте).
        $this->assertStringNotContainsString('id="scw-name"', $this->hiddenNameFragment($response->getContent()));
    }

    public function test_logged_in_student_does_not_get_the_name_field_prompt(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        // У залогиненного студента поле имени скрыто (пишет как свой User).
        $this->assertMatchesRegularExpression('/id="scw-name"[^>]*\shidden/', $response->getContent());
    }

    public function test_presence_beacon_is_wired_when_flag_on(): void
    {
        config()->set('features.support_visitor_presence', true);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-presence="1"', false);
        $response->assertSee('data-presence-url="'.route('support.presence').'"', false);
    }

    public function test_presence_beacon_is_off_when_flag_off(): void
    {
        config()->set('features.support_visitor_presence', false);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-presence="0"', false);
    }

    /** Вырезает фрагмент вокруг hidden-инпута имени, если он есть (для негативной проверки гостя). */
    private function hiddenNameFragment(string $html): string
    {
        if (preg_match('/<input[^>]*id="scw-name"[^>]*\shidden[^>]*>/', $html, $m)) {
            return $m[0];
        }

        return '';
    }

    // --- H1198 (Jivo-паритет S3): контекстное приветствие по странице входа. ---

    public function test_context_greeting_flag_is_off_by_default(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-context-greeting="0"', false);
    }

    public function test_context_greeting_flag_reflects_the_reused_suggester_flag_when_on(): void
    {
        config(['features.support_answer_suggester' => true]);
        MarketingSetting::create(['support_answer_suggester_enabled' => true]);
        MarketingSetting::flushCached();

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-context-greeting="1"', false);
    }

    public function test_context_greeting_flag_stays_off_when_only_the_deploy_flag_is_on(): void
    {
        // Оба гейта нужны — как у самого FAQ-суггестера (isEnabled()): деплой-флаг
        // включен, но админ-тумблер MarketingSetting выключен/отсутствует.
        config(['features.support_answer_suggester' => true]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('data-context-greeting="0"', false);
    }

    public function test_context_greeting_script_carries_course_and_payment_copy(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Вопрос по этому курсу', false);
        $response->assertSee('Возникли сложности с оплатой', false);
        $response->assertSee('CONTEXT_GREETING', false);
    }
}
