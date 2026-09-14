<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Filament\Resources\LeadResource\Pages\ListLeads;
use App\Jobs\SendLeadMessenger;
use App\Models\Lead;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H223 D1–D3: эргономика воронки лидов — пресет «на контакт сегодня»,
 * «Взять себе» в один клик и гарды смены статуса.
 */
class LeadWorkflowUxTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $manager = User::factory()->create(['role' => Roles::MANAGER]);
        $this->actingAs($manager);

        return $manager;
    }

    /** @test */
    public function default_preset_shows_only_leads_due_for_contact(): void
    {
        $this->manager();

        $overdue = Lead::create(['contact' => '+70000000000', 'name' => 'Просрочка', 'status' => 'new', 'next_contact_at' => today()->subDays(2)]);
        $today = Lead::create(['contact' => '+70000000000', 'name' => 'Сегодня', 'status' => 'in_work', 'next_contact_at' => today()]);
        $future = Lead::create(['contact' => '+70000000000', 'name' => 'Будущее', 'status' => 'new', 'next_contact_at' => today()->addDays(5)]);
        // Просрочен, но финальный статус — не должен всплывать в очереди на контакт.
        $rejected = Lead::create(['contact' => '+70000000000', 'name' => 'Отказ', 'status' => 'rejected', 'rejection_reason' => 'нет бюджета', 'next_contact_at' => today()->subDay()]);

        Livewire::test(ListLeads::class)
            ->assertCanSeeTableRecords([$overdue, $today])
            ->assertCanNotSeeTableRecords([$future, $rejected]);
    }

    /**
     * Список без пресета «на контакт сегодня» — чтобы экшены видели любой лид,
     * а не только просроченные (пресет по умолчанию сужает выборку таблицы).
     */
    private function listAllLeads()
    {
        return Livewire::test(ListLeads::class)
            ->set('tableFilters.due_for_contact.value', null);
    }

    /** @test */
    public function take_lead_assigns_to_me_and_sets_tomorrow(): void
    {
        $manager = $this->manager();

        $lead = Lead::create(['contact' => '+70000000000', 'name' => 'Ничей', 'status' => 'new']);

        $this->listAllLeads()
            ->callTableAction('take_lead', $lead);

        $lead->refresh();
        $this->assertSame($manager->id, $lead->assigned_to);
        $this->assertTrue(today()->addDay()->isSameDay($lead->next_contact_at));
    }

    /** @test */
    public function bulk_message_email_channel_queues_only_leads_with_address(): void
    {
        Queue::fake();
        $this->manager();

        $withEmail = Lead::create(['contact' => '+70000000000', 'name' => 'Анна', 'status' => 'new', 'email' => 'anna@example.com']);
        $noContacts = Lead::create(['contact' => '+70000000001', 'name' => 'Без контактов', 'status' => 'new']);

        $this->listAllLeads()
            ->callTableBulkAction('bulk_message', [$withEmail, $noContacts], data: [
                'text' => 'Привет, {name}',
                'channels' => ['email'],
                'subject' => 'Тема для {name}',
            ])
            ->assertHasNoTableBulkActionErrors();

        Queue::assertPushed(SendLeadMessenger::class, 1);
        Queue::assertPushed(SendLeadMessenger::class, fn (SendLeadMessenger $j) => $j->leadId === $withEmail->id
            && $j->channels === ['email']
            && $j->text === 'Привет, Анна'
            && $j->subject === 'Тема для Анна');
    }

    /** @test */
    public function bulk_message_requires_subject_when_email_channel_selected(): void
    {
        Queue::fake();
        $this->manager();

        $lead = Lead::create(['contact' => '+70000000000', 'name' => 'Анна', 'status' => 'new', 'email' => 'anna@example.com']);

        $this->listAllLeads()
            ->callTableBulkAction('bulk_message', [$lead], data: [
                'text' => 'Привет',
                'channels' => ['email'],
                'subject' => '',
            ])
            ->assertHasTableBulkActionErrors(['subject']);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function bulk_status_cannot_revert_final_status_to_new(): void
    {
        $this->manager();

        $converted = Lead::create(['contact' => '+70000000000', 'name' => 'Оплатил', 'status' => 'converted']);

        $this->listAllLeads()
            ->callTableBulkAction('set_status', [$converted], data: ['status' => 'new']);

        $this->assertSame('converted', $converted->refresh()->status);
    }

    /** @test */
    public function bulk_reject_requires_a_reason(): void
    {
        $this->manager();

        $lead = Lead::create(['contact' => '+70000000000', 'name' => 'Кандидат', 'status' => 'in_work']);

        // Без причины — валидация обязательного поля не даёт выполнить действие.
        $this->listAllLeads()
            ->callTableBulkAction('set_status', [$lead], data: ['status' => 'rejected'])
            ->assertHasTableBulkActionErrors(['rejection_reason']);

        $this->assertSame('in_work', $lead->refresh()->status);

        // С причиной — статус меняется, причина сохраняется.
        $this->listAllLeads()
            ->callTableBulkAction('set_status', [$lead], data: [
                'status' => 'rejected',
                'rejection_reason' => 'дорого',
            ]);

        $lead->refresh();
        $this->assertSame('rejected', $lead->status);
        $this->assertSame('дорого', $lead->rejection_reason);
    }
}
