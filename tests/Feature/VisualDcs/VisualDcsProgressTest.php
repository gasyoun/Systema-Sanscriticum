<?php

declare(strict_types=1);

namespace Tests\Feature\VisualDcs;

use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\ExternalLearningProgress;
use App\Models\Payment;
use App\Models\User;
use App\Services\Learning\ExternalLearningProgressService;
use App\Services\Learning\VisualDcsReleaseImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VisualDcsProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        config(['features.visualdcs_verb' => true]);
        app(VisualDcsReleaseImporter::class)->import(base_path('tests/fixtures/visualdcs/complete'));
    }

    private function paidUser(): User
    {
        $course = Course::factory()->create();
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 10000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        return $user->fresh();
    }

    public function test_upsert_is_idempotent_and_increments_attempts(): void
    {
        $user = $this->paidUser();
        $svc = app(ExternalLearningProgressService::class);

        $first = $svc->upsert($user, 'vdcs:v1:verb:ah', ExternalLearningProgress::STATUS_STARTED);
        $second = $svc->upsert($user, 'vdcs:v1:verb:ah', ExternalLearningProgress::STATUS_STARTED);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, $second->attempts);
        $this->assertSame(1, ExternalLearningProgress::query()->count());
    }

    public function test_second_device_resumes_same_row(): void
    {
        $user = $this->paidUser();
        $svc = app(ExternalLearningProgressService::class);
        $svc->upsert($user, 'vdcs:v1:verb:ah', ExternalLearningProgress::STATUS_STARTED, null, ['device' => 'desktop']);
        $svc->upsert($user, 'vdcs:v1:verb:ah', ExternalLearningProgress::STATUS_COMPLETED, 80, ['device' => 'phone']);

        $row = ExternalLearningProgress::query()->sole();
        $this->assertSame(ExternalLearningProgress::STATUS_COMPLETED, $row->status);
        $this->assertSame(80, $row->score);
        $this->assertSame(2, $row->attempts);
        $this->assertNotNull($row->completed_at);
    }

    public function test_unknown_object_is_rejected(): void
    {
        $this->expectException(\RuntimeException::class);
        app(ExternalLearningProgressService::class)->upsert(
            $this->paidUser(),
            'vdcs:v1:verb:not-a-root',
            ExternalLearningProgress::STATUS_STARTED,
        );
    }

    public function test_activity_events_cover_projection(): void
    {
        $user = $this->paidUser();
        $svc = app(ExternalLearningProgressService::class);
        $svc->upsert($user, 'vdcs:v1:verb:ah', ExternalLearningProgress::STATUS_STARTED);
        $svc->upsert($user, 'vdcs:v1:verb:ah', ExternalLearningProgress::STATUS_COMPLETED);

        $this->assertTrue($svc->countsReconcile($user));
        $this->assertGreaterThanOrEqual(2, ActivityEvent::query()->where('user_id', $user->id)->count());
    }

    public function test_form_post_saves_progress(): void
    {
        $user = $this->paidUser();

        $this->actingAs($user)
            ->post('/dvaram/visualdcs/verb/'.rawurlencode('vdcs:v1:verb:ah').'/progress', [
                'status' => 'completed',
                'score' => 70,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('external_learning_progress', [
            'user_id' => $user->id,
            'object_id' => 'vdcs:v1:verb:ah',
            'status' => 'completed',
            'score' => 70,
        ]);
    }

    public function test_unpaid_cannot_write_progress(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/dvaram/visualdcs/verb/'.rawurlencode('vdcs:v1:verb:ah').'/progress', [
                'status' => 'completed',
            ])
            ->assertNotFound();

        $this->assertSame(0, ExternalLearningProgress::query()->count());
    }
}
