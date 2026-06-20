<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Debtors;
use App\Jobs\SendMessengerAlerts;
use App\Mail\DebtorReminderMail;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Email-канал в напоминаниях должникам: письмо уходит всем должникам с email
 * (без оглядки на wants_email_announcements) и содержит прямую ссылку на курс.
 */
class DebtorEmailReminderTest extends TestCase
{
    use RefreshDatabase;

    private Course $course;

    private function makeDebtor(array $attrs = []): User
    {
        // no_payment-должник: в группе курса, реального платежа нет.
        $user = User::factory()->create(array_merge([
            'wants_email_announcements' => false,
        ], $attrs));

        $group = Group::create(['name' => 'Поток А']);
        $this->course->groups()->attach($group->id);
        $user->groups()->attach($group->id);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->course = Course::factory()->create(['is_active' => true, 'slug' => 'test-course']);
        CourseBlock::factory()->for($this->course)->current()->create(['number' => 1]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);
    }

    /** @test */
    public function email_reminder_queues_regardless_of_optout_and_carries_pay_link(): void
    {
        Mail::fake();
        Queue::fake();

        $debtor = $this->makeDebtor(['email' => 'debtor@example.com', 'telegram_id' => null, 'vk_id' => null]);

        Livewire::actingAs($this->admin())->test(Debtors::class)
            ->callTableBulkAction('send_reminder', [$debtor->getKey()], data: [
                'text' => 'Оплатить курс: {pay_link}',
                'subject' => 'Долг по «{course}»',
                'to_telegram' => false,
                'to_vk' => false,
                'to_email' => true,
            ]);

        Mail::assertQueued(DebtorReminderMail::class, function (DebtorReminderMail $mail) use ($debtor) {
            return $mail->hasTo($debtor->email)
                && str_contains($mail->bodyText, route('student.course', 'test-course'));
        });
        // Мессенджеры не трогаем, если каналы выключены.
        Queue::assertNotPushed(SendMessengerAlerts::class);
    }

    /** @test */
    public function messenger_still_dispatched_when_enabled(): void
    {
        Mail::fake();
        Queue::fake();

        $debtor = $this->makeDebtor(['email' => 'd2@example.com', 'telegram_id' => '123456']);

        Livewire::actingAs($this->admin())->test(Debtors::class)
            ->callTableBulkAction('send_reminder', [$debtor->getKey()], data: [
                'text' => 'Напоминание {name}',
                'subject' => 'Тема',
                'to_telegram' => true,
                'to_vk' => false,
                'to_email' => true,
            ]);

        Queue::assertPushed(SendMessengerAlerts::class);
        Mail::assertQueued(DebtorReminderMail::class);
    }

    /** @test */
    public function debtor_without_valid_email_gets_no_mail(): void
    {
        Mail::fake();
        Queue::fake();

        // email NOT NULL в схеме → «нет почты» = невалидный адрес.
        $debtor = $this->makeDebtor(['email' => 'no-email-placeholder', 'telegram_id' => '777']);

        Livewire::actingAs($this->admin())->test(Debtors::class)
            ->callTableBulkAction('send_reminder', [$debtor->getKey()], data: [
                'text' => 'Текст',
                'subject' => 'Тема',
                'to_telegram' => true,
                'to_vk' => false,
                'to_email' => true,
            ]);

        Mail::assertNothingQueued();
        Queue::assertPushed(SendMessengerAlerts::class);
    }
}
