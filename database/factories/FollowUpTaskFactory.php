<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Deal;
use App\Models\FollowUpTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FollowUpTask>
 */
class FollowUpTaskFactory extends Factory
{
    protected $model = FollowUpTask::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'assigned_to' => null,
            'type' => FollowUpTask::TYPE_CALL,
            'due_at' => now(),
            'done_at' => null,
        ];
    }

    /** Задача с наступившим (просроченным) сроком. */
    public function overdue(int $days = 3): static
    {
        return $this->state(fn (): array => ['due_at' => now()->subDays($days)]);
    }

    /** Задача на будущее — в бакет «на сегодня» попадать не должна. */
    public function upcoming(int $days = 7): static
    {
        return $this->state(fn (): array => ['due_at' => now()->addDays($days)]);
    }

    /** Уже закрытая задача. */
    public function done(): static
    {
        return $this->state(fn (): array => ['done_at' => now()]);
    }
}
