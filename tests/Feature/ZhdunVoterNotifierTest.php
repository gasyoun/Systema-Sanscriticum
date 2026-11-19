<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\CourseWaitlistItemResource\Pages\ListCourseWaitlistItems;
use App\Jobs\SendMessengerAlerts;
use App\Jobs\SendTelegramChatMessageJob;
use App\Mail\ZhdunPaymentOpenMail;
use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\Tariff;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ждун: голосовавшие узнают, что оплата открыта. Авто — из
 * OpenWaitlistPayment за флагом waitlist_notify_voters, раз на строку;
 * вручную — кнопка «Сообщить голосовавшим» в админке при payment_open.
 * Голосовавший без привязанного TG/VK — ручной хвост, не шлём.
 */
class ZhdunVoterNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.waitlist_voting' => true,
            'features.waitlist_notify_voters' => true,
            'services.telegram.curators_chat_id' => '12345',
        ]);
        Queue::fake();
        Mail::fake();
    }

    private function boundItem(int $minPayers = 2, string $status = CourseWaitlistItem::STATUS_COLLECTING): CourseWaitlistItem
    {
        $item = CourseWaitlistItem::create([
            'slug' => 'voters-'.uniqid(),
            'course_id' => Course::factory()->create(['title' => 'Философия санкхьи'])->id,
            'course_title' => 'Философия санкхьи',
            'teacher_name' => 'Тест',
            'min_payers' => $minPayers,
            'kind' => 'other',
            'status' => $status,
        ]);
        Tariff::factory()->create(['course_id' => $item->course_id, 'is_active' => false]);

        return $item;
    }

    /** По умолчанию — голосовавший только с Telegram, от email-рассылок отписан. */
    private function vote(CourseWaitlistItem $item, array $userAttrs = []): User
    {
        $user = User::factory()->create($userAttrs + ['telegram_id' => '777', 'wants_email_announcements' => false]);
        $item->votes()->create(['user_id' => $user->id]);

        return $user;
    }

    /** @return Collection<int, SendMessengerAlerts> */
    private function voterAlerts(): Collection
    {
        return Queue::pushed(SendMessengerAlerts::class);
    }

    /** @return Collection<int, string> */
    private function curatorTexts(): Collection
    {
        return Queue::pushed(SendTelegramChatMessageJob::class)
            ->map(fn (SendTelegramChatMessageJob $job) => $job->text);
    }

    public function test_quorum_notifies_every_voter_with_course_link(): void
    {
        $item = $this->boundItem(minPayers: 2);
        $a = $this->vote($item);
        $b = $this->vote($item, ['telegram_id' => null, 'vk_id' => '888']);

        $this->artisan('waitlist:process')->assertSuccessful();

        $this->assertSame(CourseWaitlistItem::STATUS_PAYMENT_OPEN, $item->refresh()->status);
        $alerts = $this->voterAlerts();
        $this->assertSame(2, $alerts->count());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $alerts->map(fn ($job) => $job->user->id)->all());

        $text = $alerts->first()->text;
        $this->assertStringContainsString('Оплата открыта: Философия санкхьи', $text);
        $this->assertStringContainsString('/k/'.$item->course->slug, $text);

        $this->assertTrue($this->curatorTexts()->contains(
            fn (string $t) => str_contains($t, 'мессенджеры <b>2</b>, email <b>0</b>')
        ));
        Mail::assertNothingQueued();
    }

    public function test_opted_in_voter_gets_email_with_unsubscribe(): void
    {
        $item = $this->boundItem(minPayers: 1);
        $voter = $this->vote($item, ['telegram_id' => null, 'wants_email_announcements' => true]);

        $this->artisan('waitlist:process')->assertSuccessful();

        $this->assertSame(0, $this->voterAlerts()->count());
        Mail::assertQueued(ZhdunPaymentOpenMail::class, function (ZhdunPaymentOpenMail $mail) use ($voter, $item): bool {
            $this->assertSame('Оплата открыта: Философия санкхьи', $mail->envelope()->subject);
            $this->assertNotNull($mail->unsubscribeUrl());
            $mail->assertSeeInHtml(route('shop.course.show', $item->course), false);
            $mail->assertSeeInHtml('Отписаться от рассылки');

            return $mail->hasTo($voter->email);
        });
        $this->assertTrue($this->curatorTexts()->contains(
            fn (string $t) => str_contains($t, 'мессенджеры <b>0</b>, email <b>1</b>')
        ));
    }

    public function test_voter_with_messenger_and_email_gets_both(): void
    {
        $item = $this->boundItem(minPayers: 1);
        $this->vote($item, ['wants_email_announcements' => true]);

        $this->artisan('waitlist:process')->assertSuccessful();

        $this->assertSame(1, $this->voterAlerts()->count());
        Mail::assertQueued(ZhdunPaymentOpenMail::class, 1);
    }

    public function test_unreachable_voter_is_counted_not_sent(): void
    {
        $item = $this->boundItem(minPayers: 2);
        $this->vote($item);
        // Ни мессенджера, ни согласия на email — только вручную.
        $this->vote($item, ['telegram_id' => null, 'vk_id' => null]);

        $this->artisan('waitlist:process')->assertSuccessful();

        $this->assertSame(1, $this->voterAlerts()->count());
        Mail::assertNothingQueued();
        $this->assertTrue($this->curatorTexts()->contains(
            fn (string $t) => str_contains($t, 'не охвачено (вручную): <b>1</b>')
        ));
    }

    public function test_flag_off_opens_payment_without_voter_messages(): void
    {
        config(['features.waitlist_notify_voters' => false]);
        $item = $this->boundItem(minPayers: 1);
        $this->vote($item);

        $this->artisan('waitlist:process')->assertSuccessful();

        $this->assertSame(CourseWaitlistItem::STATUS_PAYMENT_OPEN, $item->refresh()->status);
        $this->assertSame(0, $this->voterAlerts()->count());
        $this->assertTrue($this->curatorTexts()->contains(
            fn (string $t) => str_contains($t, 'не рассылалось')
        ));
    }

    public function test_auto_notice_goes_once_per_item(): void
    {
        $item = $this->boundItem(minPayers: 1);
        $this->vote($item);

        $this->artisan('waitlist:process')->assertSuccessful();
        // Строку вернули в сбор и кворум сработал снова — голосовавшим второй раз не пишем.
        $item->update(['status' => CourseWaitlistItem::STATUS_COLLECTING]);
        $this->artisan('waitlist:process')->assertSuccessful();

        $this->assertSame(1, $this->voterAlerts()->count());
    }

    public function test_blocked_quorum_sends_nothing_to_voters(): void
    {
        $item = $this->boundItem(minPayers: 1);
        Tariff::query()->where('course_id', $item->course_id)->delete();
        $this->vote($item);

        $this->artisan('waitlist:process')->assertSuccessful();

        $this->assertSame(CourseWaitlistItem::STATUS_COLLECTING, $item->refresh()->status);
        $this->assertSame(0, $this->voterAlerts()->count());
    }

    public function test_admin_button_sends_custom_text_to_voters(): void
    {
        config(['features.waitlist_notify_voters' => false]); // кнопка от флага не зависит
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        $item = $this->boundItem(status: CourseWaitlistItem::STATUS_PAYMENT_OPEN);
        $this->vote($item);
        $this->vote($item);

        Livewire::test(ListCourseWaitlistItems::class)
            ->callTableAction('notify_voters', $item, data: ['text' => 'Оплата открыта, ждём вас'])
            ->assertHasNoTableActionErrors();

        $alerts = $this->voterAlerts();
        $this->assertSame(2, $alerts->count());
        $this->assertSame('Оплата открыта, ждём вас', $alerts->first()->text);
    }

    public function test_admin_button_text_reaches_email_escaped_once(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        $item = $this->boundItem(status: CourseWaitlistItem::STATUS_PAYMENT_OPEN);
        $this->vote($item, ['telegram_id' => null, 'wants_email_announcements' => true]);

        Livewire::test(ListCourseWaitlistItems::class)
            ->callTableAction('notify_voters', $item, data: ['text' => "Курс «Санкхья» & практика\nждём <вас>"])
            ->assertHasNoTableActionErrors();

        Mail::assertQueued(ZhdunPaymentOpenMail::class, function (ZhdunPaymentOpenMail $mail): bool {
            $mail->assertSeeInHtml('«Санкхья» &amp; практика<br />', false);
            $mail->assertSeeInHtml('ждём &lt;вас&gt;', false);

            return true;
        });
    }

    public function test_admin_button_hidden_while_collecting(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]));
        $item = $this->boundItem();
        $this->vote($item);

        Livewire::test(ListCourseWaitlistItems::class)
            ->assertTableActionHidden('notify_voters', $item);
    }
}
