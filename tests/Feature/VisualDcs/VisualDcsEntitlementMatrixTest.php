<?php

declare(strict_types=1);

namespace Tests\Feature\VisualDcs;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\Learning\VisualDcsReleaseImporter;
use App\Support\Roles;
use App\Support\VisualDcsEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VisualDcsEntitlementMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        config([
            'features.visualdcs_verb' => true,
            'features.visualdcs_nominal' => true,
            'features.visualdcs_passage' => true,
        ]);
        app(VisualDcsReleaseImporter::class)->import(base_path('tests/fixtures/visualdcs/complete'));
    }

    public function test_public_preview_is_bounded_and_full_tier_only(): void
    {
        $this->get('/visualdcs/verb/preview')
            ->assertOk()
            ->assertSee('ah')
            ->assertDontSee('abhibhañj');
    }

    public function test_unpaid_user_sees_preview_not_attested(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/dvaram/visualdcs/verb')
            ->assertOk()
            ->assertSee('ah')
            ->assertDontSee('abhibhañj');

        $this->assertSame(VisualDcsEntitlement::UNPAID, VisualDcsEntitlement::matrixState($user));
    }

    public function test_paid_full_sees_attested(): void
    {
        $course = Course::factory()->create(['slug' => 'sanskrit-1']);
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->actingAs($user->fresh())
            ->get('/dvaram/visualdcs/verb')
            ->assertOk()
            ->assertSee('ah')
            ->assertSee('abhibhañj');

        $this->assertSame(VisualDcsEntitlement::FULL, VisualDcsEntitlement::matrixState($user->fresh()));
    }

    public function test_partial_when_only_some_configured_courses_paid(): void
    {
        $a = Course::factory()->create(['slug' => 'course-a']);
        Course::factory()->create(['slug' => 'course-b']);
        config(['visualdcs.entitlement_course_slugs' => ['course-a', 'course-b']]);

        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $a->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->assertSame(VisualDcsEntitlement::PARTIAL, VisualDcsEntitlement::matrixState($user->fresh()));
        $this->assertTrue(VisualDcsEntitlement::hasFullAccess($user->fresh()));
    }

    public function test_expired_canceled_payment_is_preview_only(): void
    {
        $course = Course::factory()->create();
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'canceled',
        ]);

        $this->assertSame(VisualDcsEntitlement::EXPIRED, VisualDcsEntitlement::matrixState($user->fresh()));
        $this->actingAs($user->fresh())
            ->get('/dvaram/visualdcs/verb')
            ->assertOk()
            ->assertDontSee('abhibhañj');
    }

    public function test_admin_sees_attested_without_payment(): void
    {
        $admin = User::factory()->create(['role' => Roles::SUPER_ADMIN]);

        $this->assertSame(VisualDcsEntitlement::ADMIN, VisualDcsEntitlement::matrixState($admin));
        $this->actingAs($admin)
            ->get('/dvaram/visualdcs/verb')
            ->assertOk()
            ->assertSee('abhibhañj');
    }

    public function test_deposit_does_not_grant_full_access(): void
    {
        $course = Course::factory()->create();
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $this->assertFalse(VisualDcsEntitlement::hasFullAccess($user->fresh()));
    }
}
