<?php

declare(strict_types=1);

namespace Tests\Feature\MiniCourse;

use App\Http\Controllers\MiniCourseQuickEnrollController;
use App\Mail\MiniCourseMagicLinkMail;
use App\Models\Course;
use App\Models\Group;
use App\Models\MagicLinkToken;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use App\Support\MiniCourseDrip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Быстрая запись на бесплатный мини-курс (ссылка VK-бота): имя + email →
 * кабинет, платёж 0 ₽ по штатному пути, автовход, сразу в курс. Существующий
 * email — только одноразовая ссылка, без входа. Платный тариф — никогда.
 */
class MiniCourseQuickEnrollTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'podgotovitelnaya-gruppa-sanskrita';

    private Course $course;

    private Group $group;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        Queue::fake();
        Mail::fake();
        Http::fake(['enter.tochka.com/*' => Http::response([], 500)]);

        config([
            'features.mini_course_quick_enroll' => true,
            'mini_courses.slug' => self::SLUG,
        ]);

        $this->course = Course::factory()->create(['slug' => self::SLUG, 'title' => 'Подготовительная группа']);
        $this->group = Group::create(['name' => 'Подготовительная группа', 'status' => 'forming']);
        $this->course->groups()->attach($this->group->id);
        Tariff::factory()->for($this->course)->create([
            'title' => 'Бесплатно',
            'type' => 'full',
            'price' => 0,
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Анна',
            'email' => 'anna-new@example.com',
            'pd_consent' => '1',
            'website' => '',
            'ff_ts' => encrypt((string) (now()->timestamp - 10)),
        ], $overrides);
    }

    private function url(): string
    {
        return route('mini-course.quick.store');
    }

    /** @test */
    public function flag_off_hides_the_page_and_the_endpoint(): void
    {
        config(['features.mini_course_quick_enroll' => false]);

        $this->get(route('mini-course.quick'))->assertNotFound();
        $this->post($this->url(), $this->payload())->assertNotFound();
        $this->assertSame(0, User::count());
    }

    /** @test */
    public function page_renders_the_short_form(): void
    {
        $this->get(route('mini-course.quick'))
            ->assertOk()
            ->assertSee('name="name"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="pd_consent"', false)
            ->assertDontSee('name="surname"', false)
            ->assertDontSee('name="city"', false);
    }

    /** @test */
    public function new_email_gets_a_cabinet_free_enrollment_and_is_logged_in(): void
    {
        $this->post($this->url(), $this->payload())
            ->assertRedirect(route('student.course', self::SLUG))
            ->assertSessionHas('success');

        $user = User::where('email', 'anna-new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Анна', $user->greeting_name);

        $payment = Payment::where('user_id', $user->id)->sole();
        $this->assertSame('paid', $payment->status);
        $this->assertEquals(0, (float) $payment->amount);
        $this->assertSame($this->course->id, $payment->course_id);
        $this->assertSame('full', $payment->tariff);

        // Доступ и якорь дрипа — штатным путём Payment, не вручную.
        $this->assertTrue($this->group->users()->whereKey($user->id)->exists());
        $this->assertNotNull(MiniCourseDrip::enrolledAt($user, $this->course->id));

        Http::assertNothingSent();
    }

    /** @test */
    public function existing_email_is_not_logged_in_and_gets_a_one_time_link(): void
    {
        $owner = User::factory()->create(['email' => 'owner@example.com']);

        $this->post($this->url(), $this->payload(['email' => 'owner@example.com']))
            ->assertOk()
            ->assertSee('Проверьте почту');

        $this->assertGuest();
        $this->assertSame(0, Payment::where('user_id', $owner->id)->count());

        $magicUrl = null;
        Mail::assertQueued(MiniCourseMagicLinkMail::class, function (MiniCourseMagicLinkMail $mail) use ($owner, &$magicUrl) {
            $magicUrl = $mail->magicUrl;

            return $mail->hasTo($owner->email);
        });

        $this->get($magicUrl)->assertRedirect(route('student.course', self::SLUG));
        $this->assertAuthenticatedAs($owner);
        $this->assertSame('paid', Payment::where('user_id', $owner->id)->sole()->status);

        // Одноразовая: повтор — 404.
        auth()->logout();
        $this->get($magicUrl)->assertNotFound();
        $this->assertGuest();
    }

    /** @test */
    public function magic_link_of_another_purpose_is_rejected(): void
    {
        $owner = User::factory()->create();
        $token = MagicLinkToken::issueFor($owner, 'newsletter');

        $this->get(route('mini-course.quick.magic', $token))->assertNotFound();
        $this->assertGuest();
        $this->assertNotSame(MiniCourseQuickEnrollController::MAGIC_PURPOSE, 'newsletter');
    }

    /** @test */
    public function logged_in_user_is_enrolled_without_fields_and_only_once(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post($this->url())->assertRedirect(route('student.course', self::SLUG));
        $this->actingAs($user)->post($this->url())->assertRedirect(route('student.course', self::SLUG));

        $this->assertSame(1, Payment::where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('course_user')->where('user_id', $user->id)->count());

        // Уже записанного страница сразу отправляет в курс.
        $this->actingAs($user)->get(route('mini-course.quick'))
            ->assertRedirect(route('student.course', self::SLUG));
    }

    /** @test */
    public function paid_tariff_is_never_granted_through_this_path(): void
    {
        Tariff::query()->update(['price' => 1990]);

        $this->get(route('mini-course.quick'))->assertNotFound();
        $this->post($this->url(), $this->payload())->assertNotFound();

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, User::count());
    }

    /** @test */
    public function inactive_free_tariff_closes_the_path(): void
    {
        Tariff::query()->update(['is_active' => false]);

        $this->post($this->url(), $this->payload())->assertNotFound();
        $this->assertSame(0, Payment::count());
    }

    /** @test */
    public function missing_consent_or_name_is_a_validation_error(): void
    {
        $this->post($this->url(), $this->payload(['pd_consent' => null]))->assertSessionHasErrors('pd_consent');
        $this->post($this->url(), $this->payload(['name' => '']))->assertSessionHasErrors('name');

        $this->assertSame(0, User::count());
    }

    /** @test */
    public function bots_get_the_neutral_screen_without_account_or_mail(): void
    {
        $this->post($this->url(), $this->payload(['website' => 'http://spam']))->assertOk();
        $this->post($this->url(), $this->payload(['ff_ts' => encrypt((string) now()->timestamp)]))->assertOk();
        $this->post($this->url(), $this->payload(['ff_ts' => null]))->assertOk();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Payment::count());
        Mail::assertNothingQueued();
        $this->assertGuest();
    }

    /** @test */
    public function utm_from_the_bot_link_lands_on_the_new_user(): void
    {
        $this->get(route('mini-course.quick', [
            'utm_source' => 'vk',
            'utm_medium' => 'bot',
            'utm_campaign' => 'minikurs',
        ]))->assertOk();

        $this->post($this->url(), $this->payload());

        $user = User::where('email', 'anna-new@example.com')->firstOrFail();
        $this->assertSame('vk', $user->utm_source);
        $this->assertSame('minikurs', $user->utm_campaign);
    }

    /** @test */
    public function landing_cta_points_to_quick_enroll_and_hides_the_newsletter_popup(): void
    {
        config(['features.newsletter_subscribe' => true]);

        $this->get(route('shop.course.show', self::SLUG))
            ->assertOk()
            ->assertSee(route('mini-course.quick'), false)
            ->assertDontSee('nsp-root', false);

        config(['features.mini_course_quick_enroll' => false]);

        $this->get(route('shop.course.show', self::SLUG))
            ->assertOk()
            ->assertDontSee(route('mini-course.quick'), false)
            ->assertSee('nsp-root', false);
    }
}
