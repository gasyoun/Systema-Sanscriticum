<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\CaptureAttribution;
use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use App\Services\AttributionService;
use App\Services\Reports\ChannelConversionReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class AttributionTest extends TestCase
{
    use RefreshDatabase;

    private function landing(): LandingPage
    {
        return LandingPage::create(['title' => 'L', 'slug' => 'l-'.uniqid()]);
    }

    /** @test */
    public function middleware_captures_utm_and_referrer_on_first_visit(): void
    {
        $request = Request::create(
            '/landing?utm_source=vk&utm_medium=cpc&utm_campaign=summer&click_id=abc',
            'GET', [], [], [], ['HTTP_REFERER' => 'https://vk.com/wall1']
        );
        $request->setLaravelSession($this->app['session']->driver());

        (new CaptureAttribution)->handle($request, fn ($r) => response('ok'));

        $data = $request->session()->get(CaptureAttribution::SESSION_KEY);
        $this->assertSame('vk', $data['utm_source']);
        $this->assertSame('cpc', $data['utm_medium']);
        $this->assertSame('summer', $data['utm_campaign']);
        $this->assertSame('abc', $data['click_id']);
        $this->assertSame('https://vk.com/wall1', $data['referrer']);
    }

    /** @test */
    public function middleware_does_not_overwrite_already_captured_attribution(): void
    {
        $request = Request::create('/other?utm_source=direct2', 'GET');
        $request->setLaravelSession($this->app['session']->driver());
        $request->session()->put(CaptureAttribution::SESSION_KEY, ['utm_source' => 'vk']);

        (new CaptureAttribution)->handle($request, fn ($r) => response('ok'));

        $this->assertSame('vk', $request->session()->get(CaptureAttribution::SESSION_KEY)['utm_source']);
    }

    /** @test */
    public function middleware_is_noop_without_utm_or_referrer(): void
    {
        $request = Request::create('/plain', 'GET');
        $request->setLaravelSession($this->app['session']->driver());

        (new CaptureAttribution)->handle($request, fn ($r) => response('ok'));

        $this->assertFalse($request->session()->has(CaptureAttribution::SESSION_KEY));
    }

    /** @test */
    public function apply_to_new_user_writes_session_attribution(): void
    {
        session([CaptureAttribution::SESSION_KEY => [
            'utm_source' => 'vk', 'utm_medium' => 'cpc', 'referrer' => 'https://vk.com/x',
        ]]);

        $user = User::factory()->create();
        app(AttributionService::class)->applyToNewUser($user);

        $user->refresh();
        $this->assertSame('vk', $user->utm_source);
        $this->assertSame('cpc', $user->utm_medium);
        $this->assertSame('https://vk.com/x', $user->referrer);
    }

    /** @test */
    public function apply_to_new_user_matches_existing_lead_by_email_and_falls_back_to_its_utm(): void
    {
        $lead = Lead::create([
            'landing_page_id' => $this->landing()->id,
            'name' => 'X', 'contact' => '+1', 'email' => 'student@example.com',
            'utm_source' => 'vk', 'utm_campaign' => 'promo1',
        ]);

        // Сессия пуста (регистрация в отдельной вкладке) — атрибуция берётся из лида.
        $user = User::factory()->create(['email' => 'student@example.com']);
        app(AttributionService::class)->applyToNewUser($user);

        $user->refresh();
        $this->assertSame($lead->id, $user->lead_id);
        $this->assertSame('vk', $user->utm_source);
        $this->assertSame('promo1', $user->utm_campaign);
    }

    /** @test */
    public function apply_to_new_user_is_noop_when_nothing_to_attribute(): void
    {
        $user = User::factory()->create(['email' => 'nobody@example.com']);
        app(AttributionService::class)->applyToNewUser($user);

        $user->refresh();
        $this->assertNull($user->utm_source);
        $this->assertNull($user->lead_id);
    }

    /** @test */
    public function apply_birth_year_validates_range_and_is_non_blocking(): void
    {
        $user = User::factory()->create();
        $service = app(AttributionService::class);

        $service->applyBirthYear($user, 1990);
        $this->assertSame(1990, $user->fresh()->birth_year);

        // Мусорные значения молча игнорируются — год рождения необязателен.
        $service->applyBirthYear($user, 1800);
        $this->assertSame(1990, $user->fresh()->birth_year);

        $service->applyBirthYear($user, (int) now()->addYear()->format('Y'));
        $this->assertSame(1990, $user->fresh()->birth_year);
    }

    /** @test */
    public function channel_conversion_report_connects_lead_user_and_payment(): void
    {
        $course = Course::factory()->create();

        $vkUser = User::factory()->create(['utm_source' => 'vk']);
        Payment::create([
            'user_id' => $vkUser->id, 'course_id' => $course->id,
            'amount' => 4800, 'tariff' => 'full', 'status' => 'paid',
        ]);

        User::factory()->create(['utm_source' => 'vk']); // не оплатил
        User::factory()->create(['utm_source' => null]); // direct

        $rows = ChannelConversionReport::forMonth(now());
        $byChannel = collect($rows)->keyBy('channel');

        $this->assertSame(2, $byChannel['vk']['users']);
        $this->assertSame(1, $byChannel['vk']['paid_users']);
        $this->assertSame(50.0, $byChannel['vk']['conversion']);
        $this->assertSame(4800.0, $byChannel['vk']['revenue']);

        $this->assertSame(1, $byChannel['direct']['users']);
        $this->assertSame(0, $byChannel['direct']['paid_users']);
    }
}
