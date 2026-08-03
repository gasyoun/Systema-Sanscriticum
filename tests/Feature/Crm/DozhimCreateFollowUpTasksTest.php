<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\Course;
use App\Models\Deal;
use App\Models\DealStage;
use App\Models\FollowUpTask;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NOBORING dozhim Wave 1b (H2059): dozhim:create-followups заводит FollowUpTask
 * на недожатые open Deal, идемпотентно, гейт dozhim_queue.
 */
class DozhimCreateFollowUpTasksTest extends TestCase
{
    use RefreshDatabase;

    private function seedAgedOpenDeal(array $attrs = []): Deal
    {
        $stage = DealStage::first() ?? DealStage::factory()->create();
        $course = Course::factory()->create();
        $user = User::factory()->create();

        $deal = Deal::factory()->create(array_merge([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'stage_id' => $stage->id,
            'closed_at' => null,
        ], $attrs));

        $deal->forceFill(['created_at' => now()->subHours(30)])->save();

        return $deal;
    }

    /** @test */
    public function creates_nothing_when_flag_off(): void
    {
        config(['features.dozhim_queue' => false, 'dozhim.unpaid_deal_hours' => 24]);

        $this->seedAgedOpenDeal();

        $this->artisan('dozhim:create-followups')->assertSuccessful();

        $this->assertSame(0, FollowUpTask::query()->count());
    }

    /** @test */
    public function creates_one_open_task_per_aged_deal(): void
    {
        config(['features.dozhim_queue' => true, 'dozhim.unpaid_deal_hours' => 24]);

        $deal = $this->seedAgedOpenDeal(['assigned_to' => User::factory()->create()->id]);

        $this->artisan('dozhim:create-followups')->assertSuccessful();

        $this->assertSame(1, FollowUpTask::query()->where('deal_id', $deal->id)->count());
        $task = FollowUpTask::query()->where('deal_id', $deal->id)->first();
        $this->assertSame($deal->assigned_to, $task->assigned_to);
        $this->assertSame(FollowUpTask::TYPE_CALL, $task->type);
        $this->assertFalse($task->isDone());
    }

    /** @test */
    public function does_not_duplicate_when_an_open_task_already_exists(): void
    {
        config(['features.dozhim_queue' => true, 'dozhim.unpaid_deal_hours' => 24]);

        $deal = $this->seedAgedOpenDeal();
        FollowUpTask::factory()->for($deal)->create();

        $this->artisan('dozhim:create-followups')->assertSuccessful();

        $this->assertSame(1, FollowUpTask::query()->where('deal_id', $deal->id)->count());
    }

    /** @test */
    public function recreates_after_the_open_task_is_closed(): void
    {
        config(['features.dozhim_queue' => true, 'dozhim.unpaid_deal_hours' => 24]);

        $deal = $this->seedAgedOpenDeal();
        FollowUpTask::factory()->for($deal)->done()->create();

        $this->artisan('dozhim:create-followups')->assertSuccessful();

        $this->assertSame(2, FollowUpTask::query()->where('deal_id', $deal->id)->count());
        $this->assertSame(1, FollowUpTask::query()->where('deal_id', $deal->id)->open()->count());
    }

    /** @test */
    public function young_open_deals_get_no_task(): void
    {
        config(['features.dozhim_queue' => true, 'dozhim.unpaid_deal_hours' => 24]);

        $deal = $this->seedAgedOpenDeal();
        $deal->forceFill(['created_at' => now()->subHours(2)])->save();

        $this->artisan('dozhim:create-followups')->assertSuccessful();

        $this->assertSame(0, FollowUpTask::query()->where('deal_id', $deal->id)->count());
    }
}
