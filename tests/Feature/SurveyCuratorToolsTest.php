<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\SurveyResponse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SurveyCuratorToolsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function onboarding_banner_shows_until_submitted(): void
    {
        config(['surveys.enabled' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dvaram')
            ->assertOk()
            ->assertSee('/anketa/onboarding');

        SurveyResponse::create([
            'survey_slug' => 'onboarding',
            'user_id' => $user->id,
            'answers' => ['level' => 'С нуля'],
        ]);

        $this->actingAs($user)->get('/dvaram')
            ->assertOk()
            ->assertDontSee('/anketa/onboarding');
    }

    /** @test */
    public function audience_command_builds_churn_and_post3m_lists(): void
    {
        $course = Course::factory()->create();
        $stalled = User::factory()->create(['name' => 'Остановившаяся']);
        $gone = User::factory()->create(['name' => 'Дошедшая']);
        $veteran = User::factory()->create(['name' => 'Ветеран', 'email' => 'vet@example.ru']);

        Payment::create(['user_id' => $stalled->id, 'course_id' => $course->id, 'amount' => 6000, 'tariff' => 'block_1', 'status' => 'paid']);
        Payment::create(['user_id' => $gone->id, 'course_id' => $course->id, 'amount' => 6000, 'tariff' => 'block_1', 'status' => 'paid']);
        Payment::create(['user_id' => $gone->id, 'course_id' => $course->id, 'amount' => 6000, 'tariff' => 'block_2', 'status' => 'paid']);

        foreach ([1, 2] as $block) {
            Payment::create(['user_id' => $veteran->id, 'course_id' => $course->id, 'amount' => 6000, 'tariff' => 'block_'.$block, 'status' => 'paid',
                'created_at' => now()->subDays(120), 'updated_at' => now()->subDays(120)]);
        }

        $this->artisan('survey:audience churn-block')->expectsOutputToContain('Строк: 1');

        $files = glob(storage_path('app/survey-audience/churn-block-*.csv'));
        $csv = file_get_contents(end($files));
        $this->assertStringContainsString('Остановившаяся', $csv);
        $this->assertStringNotContainsString('Дошедшая', $csv);

        $this->artisan('survey:audience post3m')->expectsOutputToContain('Строк: 1');

        $files = glob(storage_path('app/survey-audience/post3m-*.csv'));
        $csv = file_get_contents(end($files));
        $this->assertStringContainsString('Ветеран', $csv);
        $this->assertStringNotContainsString('Остановившаяся', $csv);
    }
}
