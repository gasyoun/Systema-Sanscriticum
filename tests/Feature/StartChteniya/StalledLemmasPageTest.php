<?php

declare(strict_types=1);

namespace Tests\Feature\StartChteniya;

use App\Filament\Pages\StartChteniyaStalledLemmas;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** H2107 — teacher-facing stalled-lemma page, same idiom as TeacherAnalyticsTest. */
class StalledLemmasPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    private function entitledUser(): User
    {
        $course = Course::factory()->create(['slug' => 'start-chteniya']);
        config(['start_chteniya.course_slug' => $course->slug]);
        config([
            'features.kosha_reader' => true,
            'features.start_chteniya_cohort' => true,
        ]);
        $user = User::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 9990,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        return $user->fresh();
    }

    /** @test */
    public function admin_sees_the_page_with_stalled_lemma_data(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'role' => Roles::ADMIN]);
        $student = $this->entitledUser();

        $this->actingAs($student)->post('/dvaram/reading/hitopadesa-0/lookup', ['sentence' => 0, 'token' => 0]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($admin);

        $this->assertTrue(StartChteniyaStalledLemmas::canAccess());

        Livewire::test(StartChteniyaStalledLemmas::class)
            ->assertOk()
            ->assertSee('Топ застрявших лемм');
    }

    /** @test */
    public function a_plain_student_cannot_access_the_page(): void
    {
        $student = User::factory()->create();

        $this->actingAs($student);

        $this->assertFalse(StartChteniyaStalledLemmas::canAccess());
    }
}
