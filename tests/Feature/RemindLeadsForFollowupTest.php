<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTelegramMessageJob;
use App\Models\Lead;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RemindLeadsForFollowupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config()->set('features.crm_reminders', true);
    }

    private function manager(bool $withTelegram = true): User
    {
        return User::factory()->create([
            'role' => Roles::MANAGER,
            'telegram_id' => $withTelegram ? 555000 + random_int(1, 9999) : null,
        ]);
    }

    private function lead(array $overrides = []): Lead
    {
        return Lead::create(array_merge([
            'name' => 'Лид',
            'contact' => '+70000000000',
            'status' => 'in_work',
            'next_contact_at' => now()->subDay(),
        ], $overrides));
    }

    /** @test */
    public function flag_off_is_a_noop(): void
    {
        config()->set('features.crm_reminders', false);
        $manager = $this->manager();
        $lead = $this->lead(['assigned_to' => $manager->id]);

        $this->artisan('leads:remind-followup')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull($lead->fresh()->reminded_at);
    }

    /** @test */
    public function pings_assignee_and_stamps_reminded_at(): void
    {
        $manager = $this->manager();
        $lead = $this->lead(['assigned_to' => $manager->id]);

        $this->artisan('leads:remind-followup')->assertSuccessful();

        Queue::assertPushed(
            SendTelegramMessageJob::class,
            fn (SendTelegramMessageJob $job): bool => $job->userId === $manager->id,
        );
        $this->assertNotNull($lead->fresh()->reminded_at);
    }

    /** @test */
    public function excludes_future_final_unassigned_and_already_reminded(): void
    {
        $manager = $this->manager();

        $this->lead(['assigned_to' => $manager->id, 'next_contact_at' => now()->addWeek()]); // будущее
        $this->lead(['assigned_to' => $manager->id, 'status' => 'converted']);                 // финальный
        $this->lead(['assigned_to' => null]);                                                  // без ответственного
        $this->lead(['assigned_to' => $manager->id, 'reminded_at' => now()]);                  // уже сегодня

        $this->artisan('leads:remind-followup')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    /** @test */
    public function unreachable_manager_is_not_stamped(): void
    {
        $manager = $this->manager(withTelegram: false);
        $lead = $this->lead(['assigned_to' => $manager->id]);

        $this->artisan('leads:remind-followup')->assertSuccessful();

        Queue::assertNothingPushed();
        // Не стампим — напоминание всплывёт снова, когда менеджер подключит бота.
        $this->assertNull($lead->fresh()->reminded_at);
    }

    /** @test */
    public function groups_multiple_leads_into_one_ping_per_manager(): void
    {
        $manager = $this->manager();
        $this->lead(['assigned_to' => $manager->id]);
        $this->lead(['assigned_to' => $manager->id, 'name' => 'Лид 2']);

        $this->artisan('leads:remind-followup')->assertSuccessful();

        // Один пинг менеджеру, а не по одному на лид.
        Queue::assertPushed(SendTelegramMessageJob::class, 1);
    }
}
