<?php

declare(strict_types=1);

namespace Tests\Feature\GrammarLab;

use App\Models\Course;
use App\Models\GrammarLabEntitlement;
use App\Models\Payment;
use App\Support\GrammarLabAccess;

class GrammarLabAccessTest extends GrammarLabTestCase
{
    public function test_flag_off_is_404_even_for_admin(): void
    {
        config(['features.grammar_lab' => false]);
        $this->actingAs($this->admin())
            ->get('/dvaram/grammar-lab')
            ->assertNotFound();
        $this->get('/grammar-lab')->assertNotFound();
    }

    public function test_unentitled_student_gets_403_without_topic_payload(): void
    {
        $this->enableLab();
        $this->importFixture();

        $this->actingAs($this->student())
            ->get('/dvaram/grammar-lab')
            ->assertForbidden()
            ->assertDontSee('Три ступени чередования', false)
            ->assertDontSee('subject:grammar-lab:ablaut-grades', false);

        $this->actingAs($this->student())
            ->getJson('/dvaram/grammar-lab/search?q=гуна&format=json')
            ->assertForbidden()
            ->assertJsonMissingPath('results');
    }

    public function test_guest_protected_routes_redirect_to_login(): void
    {
        $this->enableLab();
        $this->get('/dvaram/grammar-lab')->assertRedirect();
    }

    public function test_public_landing_has_no_topic_ids(): void
    {
        $this->enableLab();
        $this->importFixture();
        $this->get('/grammar-lab')
            ->assertOk()
            ->assertSee('Лаборатория грамматики')
            ->assertDontSee('subject:grammar-lab:', false);
    }

    public function test_pilot_grant_and_admin_can_use(): void
    {
        $this->enableLab();
        $this->importFixture();

        $user = $this->student();
        GrammarLabEntitlement::query()->create([
            'user_id' => $user->id,
            'grant_kind' => 'pilot',
            'starts_at' => now()->subHour(),
        ]);

        $this->assertTrue(GrammarLabAccess::canUse($user));
        $this->actingAs($user)
            ->get('/dvaram/grammar-lab')
            ->assertOk()
            ->assertSee('Три ступени чередования');

        $this->actingAs($this->admin())
            ->get('/dvaram/grammar-lab/t/ablaut-grades')
            ->assertOk()
            ->assertSee('Три ступени чередования')
            ->assertSee('mw:54148');
    }

    public function test_revoked_and_expired_grants_deny(): void
    {
        $this->enableLab();
        $user = $this->student();
        GrammarLabEntitlement::query()->create([
            'user_id' => $user->id,
            'grant_kind' => 'pilot',
            'revoked_at' => now(),
        ]);
        $this->assertFalse(GrammarLabAccess::canUse($user));

        GrammarLabEntitlement::query()->create([
            'user_id' => $user->id,
            'grant_kind' => 'pilot',
            'ends_at' => now()->subMinute(),
        ]);
        $this->assertFalse(GrammarLabAccess::canUse($user->fresh()));
    }

    public function test_deposit_payment_does_not_grant(): void
    {
        $this->enableLab();
        $course = Course::factory()->create(['slug' => 'lab-course']);
        config(['grammar_lab.entitlement_course_slugs' => ['lab-course']]);
        $user = $this->student();
        Payment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);
        $this->assertFalse(GrammarLabAccess::canUse($user->fresh()));
    }

    public function test_paid_configured_course_grants(): void
    {
        $this->enableLab();
        $course = Course::factory()->create(['slug' => 'lab-course']);
        config(['grammar_lab.entitlement_course_slugs' => ['lab-course']]);
        $user = $this->student();
        Payment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
        $this->assertTrue(GrammarLabAccess::canUse($user->fresh()));
    }

    public function test_admin_grant_is_not_the_same_as_admin_role(): void
    {
        $this->enableLab();
        $user = $this->student();
        GrammarLabEntitlement::query()->create([
            'user_id' => $user->id,
            'grant_kind' => 'admin',
            'source_ref' => 'access-test:admin',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
        ]);
        $this->assertTrue(GrammarLabAccess::canUse($user));
        $this->assertFalse($user->isAdminLike());
    }
}
