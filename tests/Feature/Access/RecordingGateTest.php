<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Enums\MembershipTier;
use App\Models\ClubMembership;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\Membership\RecordingAccessPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H4396 — серверные ворота видеопейлоада записи (census
 * PAYWALL_CENSUS_2026-09-08 §A3: «гейт на странице, не на видео»).
 *
 * Контракт: страница урока больше не несёт сырых unlisted-ID; каждый
 * проигрыш идёт через /c/{slug}/u/{id}/video/{player}, где контроллер
 * заново проверяет грант/группу/оплату/H3916-членство. Бесплатные
 * поверхности (is_free / is_preview) отдаются гостю — ноль изъятия
 * бесплатных возможностей.
 */
class RecordingGateTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private Group $group;

    private Lesson $paidLesson;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();
        config()->set('features.membership_recording_enforce', false);
        // Политика членства считает тиры только при membership_tiered (как в
        // RecordingAccessPolicyTest).
        config()->set('features.membership_tiered', true);
        config()->set('features.club_membership', true);

        $this->course = Course::factory()->create();
        $this->group = Group::create(['name' => 'Поток '.$this->course->id]);
        $this->course->groups()->attach($this->group->id);

        $this->paidLesson = Lesson::factory()->create([
            'course_id' => $this->course->id,
            'group_id' => $this->group->id,
            'block_number' => 1,
            'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ',
        ]);

        $this->buyer = User::factory()->create();
    }

    private function gateUrl(Lesson $lesson, string $player = 'youtube'): string
    {
        return route('student.recording.gate', [$this->course->slug, $lesson->id, $player]);
    }

    private function buyFull(User $user): Payment
    {
        $user->groups()->syncWithoutDetaching([$this->group->id]);

        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $this->course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
    }

    /** @test */
    public function free_lesson_video_is_served_to_a_guest(): void
    {
        $this->paidLesson->update(['is_free' => true]);

        $this->get($this->gateUrl($this->paidLesson->fresh()))
            ->assertRedirect('https://www.youtube.com/embed/dQw4w9WgXcQ?enablejsapi=1&rel=0');
    }

    /** @test */
    public function preview_lesson_video_is_served_to_a_guest(): void
    {
        // Публичный «пример урока» — единственная точка правды preview.
        $this->paidLesson->update(['is_preview' => true]);

        $this->get($this->gateUrl($this->paidLesson->fresh()))
            ->assertRedirect('https://www.youtube.com/embed/dQw4w9WgXcQ?enablejsapi=1&rel=0');
    }

    /** @test */
    public function paid_lesson_video_is_404_for_a_guest(): void
    {
        $this->get($this->gateUrl($this->paidLesson))->assertNotFound();
    }

    /** @test */
    public function paid_lesson_video_is_served_to_the_buyer(): void
    {
        $this->buyFull($this->buyer);

        $this->actingAs($this->buyer)
            ->get($this->gateUrl($this->paidLesson))
            ->assertRedirect('https://www.youtube.com/embed/dQw4w9WgXcQ?enablejsapi=1&rel=0');
    }

    /** @test */
    public function paid_lesson_video_is_404_for_a_group_member_who_did_not_pay(): void
    {
        // В группе курса виден, но не платил — страница урока и так редиректит;
        // прямая пробивка ворот 404 (не 403) — как у GatedAssetController.
        $this->buyer->groups()->syncWithoutDetaching([$this->group->id]);

        $this->actingAs($this->buyer)
            ->get($this->gateUrl($this->paidLesson))
            ->assertNotFound();
    }

    /** @test */
    public function expired_promise_stops_granting_the_video_when_the_flag_is_on(): void
    {
        config()->set('features.conditional_access_expiry', true);
        $this->buyer->groups()->syncWithoutDetaching([$this->group->id]);

        $promise = PaymentPromise::create([
            'user_id' => $this->buyer->id,
            'course_id' => $this->course->id,
            'promised_at' => now()->subDay()->toDateString(),
            'amount' => 6000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);
        Payment::create([
            'user_id' => $this->buyer->id,
            'course_id' => $this->course->id,
            'amount' => 0,
            'tariff' => 'full',
            'status' => 'paid',
            'transaction_id' => 'promise_grant_#'.$promise->id,
            'is_conditional' => true,
            'linked_promise_id' => $promise->id,
        ]);

        $this->actingAs($this->buyer)
            ->get($this->gateUrl($this->paidLesson))
            ->assertNotFound();
    }

    /** @test */
    public function live_promise_still_grants_the_video(): void
    {
        config()->set('features.conditional_access_expiry', true);
        $this->buyer->groups()->syncWithoutDetaching([$this->group->id]);

        $promise = PaymentPromise::create([
            'user_id' => $this->buyer->id,
            'course_id' => $this->course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 6000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);
        Payment::create([
            'user_id' => $this->buyer->id,
            'course_id' => $this->course->id,
            'amount' => 0,
            'tariff' => 'full',
            'status' => 'paid',
            'transaction_id' => 'promise_grant_#'.$promise->id,
            'is_conditional' => true,
            'linked_promise_id' => $promise->id,
        ]);

        $this->actingAs($this->buyer)
            ->get($this->gateUrl($this->paidLesson))
            ->assertRedirect('https://www.youtube.com/embed/dQw4w9WgXcQ?enablejsapi=1&rel=0');
    }

    /** @test */
    public function lesson_page_no_longer_carries_the_raw_unlisted_id(): void
    {
        $this->buyFull($this->buyer);

        $this->actingAs($this->buyer)
            ->get(route('student.lesson', [$this->course->slug, $this->paidLesson->id]))
            ->assertOk()
            ->assertDontSee('dQw4w9WgXcQ', false)
            ->assertDontSee('youtube.com/embed', false)
            ->assertSee('/video/youtube', false);
    }

    /** @test */
    public function recording_policy_denial_blocks_the_video_payload(): void
    {
        config()->set('features.membership_recording_enforce', true);
        $this->buyFull($this->buyer);

        // Покупатель курса без клубного членства: запись закрывает политика
        // (H3916 entitlement), тогда как страница урока остаётся открытой.
        $this->actingAs($this->buyer)
            ->get($this->gateUrl($this->paidLesson))
            ->assertNotFound();

        // Действующее клубное членство — политика снова пускает.
        ClubMembership::create([
            'user_id' => $this->buyer->id,
            'payment_id' => null,
            'tier_code' => MembershipTier::Club,
            'term_months' => 1,
            'starts_at' => now(),
            'ends_at' => now()->addMonth(),
            'grace_until' => now()->addMonth()->addDays(3),
            'grace_days' => 3,
            'source' => ClubMembership::SOURCE_MANUAL,
        ]);
        app()->forgetInstance(RecordingAccessPolicy::class);

        $this->actingAs($this->buyer->fresh())
            ->get($this->gateUrl($this->paidLesson))
            ->assertRedirect('https://www.youtube.com/embed/dQw4w9WgXcQ?enablejsapi=1&rel=0');
    }

    /** @test */
    public function unknown_player_and_missing_links_are_404(): void
    {
        $this->buyFull($this->buyer);

        $this->actingAs($this->buyer)
            ->get(route('student.recording.gate', [$this->course->slug, $this->paidLesson->id, 'vimeo']))
            ->assertNotFound();

        // youtube_url нет -> youtube-плеер не резолвится даже для купившего
        // (фолбэк по приоритету урока тоже пуст).
        $this->paidLesson->update(['youtube_url' => null]);
        $this->actingAs($this->buyer)
            ->get($this->gateUrl($this->paidLesson->fresh()))
            ->assertNotFound();
    }

    /** @test */
    public function rutube_target_resolves_through_the_gate(): void
    {
        $this->buyFull($this->buyer);
        $this->paidLesson->update(['youtube_url' => null, 'rutube_url' => 'https://rutube.ru/video/'.str_repeat('a', 32).'/?p=token']);

        // Rutube: приватный токен ?p= сохраняется при канонизации (VideoLinkNormalizer
        // semantics) — редирект несёт его же.
        $this->actingAs($this->buyer)
            ->get($this->gateUrl($this->paidLesson->fresh()))
            ->assertRedirect('https://rutube.ru/play/embed/'.str_repeat('a', 32).'?p=token');
    }

    /** @test */
    public function preview_page_embeds_only_the_gate_url(): void
    {
        // Публичный «пример урока» рендерится гостю и не несёт сырых ID.
        $this->course->update(['is_visible' => true]);
        $this->paidLesson->update(['is_preview' => true, 'is_free' => false]);

        $this->get(route('shop.course.preview', $this->course->slug))
            ->assertOk()
            ->assertDontSee('dQw4w9WgXcQ', false)
            ->assertDontSee('youtube.com/embed', false)
            ->assertSee('/video/youtube', false);
    }
}
