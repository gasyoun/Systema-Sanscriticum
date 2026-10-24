<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LandingPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Готовность лендинга к шагам n8n-бота webinar_invite / webinar_recording:
 * шаг применим, только если у лендинга заполнена ссылка (см. LeadStepMailer).
 * В таблице «Лендинги» готовность видна колонками, без открытия карточки.
 */
class LandingWebinarInviteReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function landing(array $overrides = []): LandingPage
    {
        return LandingPage::create(array_merge([
            'title' => 'L',
            'slug' => 'l-'.uniqid(),
            'is_active' => true,
        ], $overrides));
    }

    /** @test */
    public function invite_ready_when_webinar_url_filled(): void
    {
        $landing = $this->landing(['webinar_url' => 'https://zoom.us/j/1']);

        $this->assertTrue($landing->canSendWebinarInvite());
    }

    /** @test */
    public function invite_not_ready_without_webinar_url(): void
    {
        $this->assertFalse($this->landing()->canSendWebinarInvite());
        $this->assertFalse($this->landing(['webinar_url' => ''])->canSendWebinarInvite());
    }

    /** @test */
    public function recording_ready_only_with_recording_url(): void
    {
        $ready = $this->landing(['webinar_recording_url' => 'https://rutube.ru/v/1']);
        $empty = $this->landing(['webinar_recording_url' => '']);

        $this->assertTrue($ready->canSendWebinarRecording());
        $this->assertFalse($empty->canSendWebinarRecording());
        $this->assertFalse($this->landing()->canSendWebinarRecording());
    }
}
