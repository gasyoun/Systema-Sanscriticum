<?php

declare(strict_types=1);

namespace Tests\Feature\Webinar;

use App\Services\Webinar\BigBlueButtonService;
use App\Services\Webinar\WebinarProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * BigBlueButton — СКЕЛЕТ (GC-B3, H601). Проверяет только форму контракта:
 * isConfigured() отражает креды, каждый интерфейсный метод сознательно бросает
 * исключение (полная интеграция — Q4, вне области этого тикета).
 */
class BigBlueButtonServiceTest extends TestCase
{
    /** @test */
    public function is_configured_reflects_credentials(): void
    {
        config(['services.bigbluebutton.base_url' => null, 'services.bigbluebutton.secret' => null]);
        $this->assertFalse(BigBlueButtonService::fromConfig()->isConfigured());

        config(['services.bigbluebutton.base_url' => 'https://bbb.example.test', 'services.bigbluebutton.secret' => 'sek']);
        $this->assertTrue(BigBlueButtonService::fromConfig()->isConfigured());
    }

    /** @test */
    public function implements_webinar_provider(): void
    {
        $this->assertInstanceOf(WebinarProvider::class, BigBlueButtonService::fromConfig());
    }

    /** @test */
    public function create_meeting_throws_when_not_configured(): void
    {
        config(['services.bigbluebutton.base_url' => null, 'services.bigbluebutton.secret' => null]);

        $this->expectException(RuntimeException::class);
        BigBlueButtonService::fromConfig()->createMeeting(['topic' => 'Занятие']);
    }

    /** @test */
    public function create_meeting_throws_skeleton_notice_when_configured(): void
    {
        config(['services.bigbluebutton.base_url' => 'https://bbb.example.test', 'services.bigbluebutton.secret' => 'sek']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/скелет/u');
        BigBlueButtonService::fromConfig()->createMeeting(['topic' => 'Занятие']);
    }

    /** @test */
    public function fetch_participants_throws_skeleton_notice_when_configured(): void
    {
        config(['services.bigbluebutton.base_url' => 'https://bbb.example.test', 'services.bigbluebutton.secret' => 'sek']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/скелет/u');
        BigBlueButtonService::fromConfig()->fetchParticipants('meeting-1');
    }

    /** @test */
    public function normalize_webhook_throws_skeleton_notice(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/скелет/u');
        BigBlueButtonService::fromConfig()->normalizeWebhook(['event' => 'meeting-ended']);
    }
}
