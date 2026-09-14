<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Filament\Pages\Customer360;
use App\Models\Course;
use App\Models\Deal;
use App\Models\FollowUpTask;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class Customer360PageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.crm_customer_360' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN]);
    }

    /** @test */
    public function manager_sees_expected_next_action_and_source_link(): void
    {
        $admin = $this->admin();
        $email = 'page@example.test';
        Lead::factory()->create([
            'email' => $email,
            'contact' => $email,
            'utm_source' => 'site',
        ]);
        $student = User::factory()->create(['name' => 'Павел Покупатель', 'email' => $email]);
        $course = Course::factory()->create(['title' => 'Курс Бета', 'is_active' => true]);
        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ]));
        Deal::factory()->won()->create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'source_payment_id' => $payment->id,
        ]);

        Livewire::actingAs($admin)
            ->withQueryParams(['user' => $student->id])
            ->test(Customer360::class)
            ->assertSee('Павел Покупатель')
            ->assertSee('Довести до первого действия в кабинете')
            ->assertSee('ActivityEvent')
            ->assertSee('paid')
            ->assertSee('/admin/payments/'.$payment->id.'/edit');
    }

    /** @test */
    public function complete_and_create_follow_up_from_the_workspace(): void
    {
        $admin = $this->admin();
        $student = User::factory()->create();
        $deal = Deal::factory()->create(['user_id' => $student->id]);
        $task = FollowUpTask::factory()->overdue()->create([
            'deal_id' => $deal->id,
            'assigned_to' => $admin->id,
        ]);

        Livewire::actingAs($admin)
            ->withQueryParams(['user' => $student->id])
            ->test(Customer360::class)
            ->assertSee('Закрыть просроченную задачу')
            ->call('completeTask', $task->id)
            ->assertHasNoErrors();

        $this->assertTrue($task->fresh()->isDone());

        Livewire::actingAs($admin)
            ->withQueryParams(['user' => $student->id])
            ->test(Customer360::class)
            ->set('taskType', FollowUpTask::TYPE_MESSAGE)
            ->set('taskDue', now()->addDay()->toDateString())
            ->set('taskNote', 'написать в TG')
            ->call('createTask')
            ->assertHasNoErrors();

        $this->assertTrue(
            FollowUpTask::query()
                ->where('deal_id', $deal->id)
                ->where('note', 'написать в TG')
                ->exists()
        );
    }

    /** @test */
    public function teacher_cannot_open_the_workspace_even_with_flag_on(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));
        $this->assertFalse(Customer360::canAccess());
    }

    /** @test */
    public function search_finds_user_by_name_tokens_in_any_order(): void
    {
        $admin = $this->admin();
        $student = User::factory()->create(['name' => 'Цыди Анна Петровна, Швейцария']);
        User::factory()->create(['name' => 'Смирнова Анна Сергеевна']);

        Livewire::actingAs($admin)
            ->test(Customer360::class)
            ->set('lookup', 'Анна Цыди')
            ->call('search')
            ->assertHasNoErrors()
            ->assertRedirect(Customer360::urlForUser($student->id));
    }

    /** @test */
    public function search_finds_lead_by_name_tokens_in_any_order(): void
    {
        $admin = $this->admin();
        $lead = Lead::factory()->create(['name' => 'Цыди Анна Петровна, Швейцария']);

        Livewire::actingAs($admin)
            ->test(Customer360::class)
            ->set('lookup', 'Анна Цыди')
            ->call('search')
            ->assertHasNoErrors()
            ->assertRedirect(Customer360::urlForLead($lead->id));
    }

    /** @test */
    public function search_still_finds_user_by_exact_email(): void
    {
        $admin = $this->admin();
        $student = User::factory()->create(['email' => 'tsidi@example.test']);

        Livewire::actingAs($admin)
            ->test(Customer360::class)
            ->set('lookup', 'tsidi@example.test')
            ->call('search')
            ->assertHasNoErrors()
            ->assertRedirect(Customer360::urlForUser($student->id));
    }

    /** @test */
    public function search_warns_when_no_client_matches(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(Customer360::class)
            ->set('lookup', 'Несуществующая Фамилия')
            ->call('search')
            ->assertHasNoErrors()
            ->assertNotified('Клиент не найден');
    }

    /** @test */
    public function page_does_not_write_payments_when_creating_a_task(): void
    {
        $admin = $this->admin();
        $student = User::factory()->create();
        $course = Course::factory()->create(['is_active' => true]);
        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 1200,
            'tariff' => 'full',
            'status' => 'pending',
            'is_conditional' => false,
        ]));
        Deal::factory()->create(['user_id' => $student->id, 'source_payment_id' => $payment->id]);

        Livewire::actingAs($admin)
            ->withQueryParams(['user' => $student->id])
            ->test(Customer360::class)
            ->call('createTask');

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(1200, (int) $payment->fresh()->amount);
    }
}
