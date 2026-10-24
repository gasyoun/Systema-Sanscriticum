<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\CourseWaitlistItem;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Автооткрытие оплаты ждун-курса (кворум голосов): строка, привязанная к
 * курсу, при votes >= min_payers сама переводится в payment_open, тарифы
 * курса включаются, куратору уходит сигнал. Прогноз-гейт остаётся только
 * для непривязанных строк; отзыв голоса оплату не закрывает; флаг
 * waitlist_auto_payment выключает весь контур.
 */
class WaitlistAutoOpenPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.waitlist_voting' => true,
            'services.telegram.curators_chat_id' => '12345',
        ]);
        Queue::fake();
    }

    private function boundItem(int $minPayers = 2, array $courseAttrs = []): CourseWaitlistItem
    {
        return CourseWaitlistItem::create([
            'slug' => 'auto-'.uniqid(),
            'course_id' => Course::factory()->create($courseAttrs)->id,
            'course_title' => 'Философия санкхьи',
            'teacher_name' => 'Леонов Максим Владимирович',
            'min_payers' => $minPayers,
            'kind' => 'other',
            'status' => CourseWaitlistItem::STATUS_COLLECTING,
        ]);
    }

    private function vote(CourseWaitlistItem $item): User
    {
        $user = User::factory()->create();
        $item->votes()->create(['user_id' => $user->id]);

        return $user;
    }

    private function makeTariffs(CourseWaitlistItem $item, int $count, bool $active = false): void
    {
        Tariff::factory()->count($count)->create([
            'course_id' => $item->course_id,
            'is_active' => $active,
        ]);
    }

    private function activeTariffs(CourseWaitlistItem $item): int
    {
        return Tariff::query()->where('course_id', $item->course_id)->where('is_active', true)->count();
    }

    /** @return Collection<int, string> */
    private function pushedTexts(): Collection
    {
        return Queue::pushed(SendTelegramChatMessageJob::class)
            ->map(fn (SendTelegramChatMessageJob $job) => $job->text);
    }

    private function webVote(string $slug): void
    {
        $this->actingAs(User::factory()->create())
            ->from(route('shop.waitlist'))
            ->post(route('shop.waitlist.vote'), ['slug' => $slug])
            ->assertRedirect(route('shop.waitlist'));
    }

    // ================= кворумный голос =================

    public function test_quorum_vote_opens_payment_and_activates_tariffs(): void
    {
        $item = $this->boundItem(minPayers: 2);
        $this->makeTariffs($item, 2);
        $this->vote($item); // 1/2 — порог ещё не набран

        $this->webVote($item->slug); // 2/2 — кворум

        $item->refresh();
        $this->assertSame(CourseWaitlistItem::STATUS_PAYMENT_OPEN, $item->status);
        $this->assertSame(2, $this->activeTariffs($item));
        $this->assertTrue($this->pushedTexts()->contains(
            fn (string $text) => str_contains($text, 'оплата открыта')
        ));
    }

    public function test_vote_below_threshold_changes_nothing(): void
    {
        $item = $this->boundItem(minPayers: 2);
        $this->makeTariffs($item, 2);

        $this->webVote($item->slug); // 1/2

        $item->refresh();
        $this->assertSame(CourseWaitlistItem::STATUS_COLLECTING, $item->status);
        $this->assertSame(0, $this->activeTariffs($item));
        $this->assertSame(0, $this->pushedTexts()->count());
    }

    public function test_quorum_vote_twice_notifies_once(): void
    {
        $item = $this->boundItem(minPayers: 1);
        $this->makeTariffs($item, 1);

        $this->webVote($item->slug); // кворум
        $this->webVote($item->slug); // уже открыт — тишина

        $item->refresh();
        $this->assertSame(CourseWaitlistItem::STATUS_PAYMENT_OPEN, $item->status);
        $this->assertSame(1, $this->pushedTexts()->count());
    }

    public function test_unvote_below_threshold_leaves_payment_open(): void
    {
        $item = $this->boundItem(minPayers: 2);
        $this->makeTariffs($item, 2, active: true);
        $item->update(['status' => CourseWaitlistItem::STATUS_PAYMENT_OPEN]);
        $voter = $this->vote($item);

        $this->actingAs($voter)
            ->from(route('shop.waitlist'))
            ->post(route('shop.waitlist.unvote'), ['slug' => $item->slug])
            ->assertRedirect(route('shop.waitlist'));

        // Оплату не закрываем: тарифы и статус остаются.
        $item->refresh();
        $this->assertSame(CourseWaitlistItem::STATUS_PAYMENT_OPEN, $item->status);
        $this->assertSame(2, $this->activeTariffs($item));
    }

    public function test_guest_vote_opens_payment_after_login(): void
    {
        $item = $this->boundItem(minPayers: 1);
        $this->makeTariffs($item, 1);
        User::factory()->create(['email' => 'voter@example.com', 'password' => Hash::make('secret123')]);

        // Гость голосует — голос ждёт в сессии.
        $this->postJson(route('shop.waitlist.vote'), ['slug' => $item->slug])
            ->assertStatus(401);

        $this->post(route('login.post'), ['email' => 'voter@example.com', 'password' => 'secret123'])
            ->assertRedirect(route('shop.waitlist'));

        $item->refresh();
        $this->assertSame(CourseWaitlistItem::STATUS_PAYMENT_OPEN, $item->status);
        $this->assertSame(1, $this->activeTariffs($item));
    }

    // ================= блокирующие проблемы =================

    public function test_course_without_tariffs_notifies_curator_and_stays_collecting(): void
    {
        $item = $this->boundItem(minPayers: 1); // тарифов нет вовсе

        $this->webVote($item->slug);

        $item->refresh();
        $this->assertSame(CourseWaitlistItem::STATUS_COLLECTING, $item->status);
        $this->assertTrue($this->pushedTexts()->contains(
            fn (string $text) => str_contains($text, 'нет тарифов')
        ));
    }

    public function test_hidden_course_notifies_curator_and_stays_collecting(): void
    {
        $item = $this->boundItem(minPayers: 1, courseAttrs: ['is_visible' => false]);
        $this->makeTariffs($item, 2);

        $this->webVote($item->slug);

        $item->refresh();
        $this->assertSame(CourseWaitlistItem::STATUS_COLLECTING, $item->status);
        $this->assertSame(0, $this->activeTariffs($item));
        $this->assertTrue($this->pushedTexts()->contains(
            fn (string $text) => str_contains($text, 'скрыт')
        ));
    }

    public function test_blocked_notification_is_deduplicated(): void
    {
        $item = $this->boundItem(minPayers: 1); // тарифов нет

        $this->webVote($item->slug);
        $this->webVote($item->slug);
        $this->webVote($item->slug);

        $blocked = $this->pushedTexts()->filter(
            fn (string $text) => str_contains($text, 'нет тарифов')
        );
        $this->assertSame(1, $blocked->count());
    }

    // ================= крон waitlist:process =================

    public function test_cron_opens_bound_course_on_pure_threshold(): void
    {
        $item = $this->boundItem(minPayers: 2);
        $this->makeTariffs($item, 1);
        // Прогноз слабый (2 голоса × 0.5 = 1, потолок истории 0), но для
        // привязанного курса он больше не гейтит.
        $item->update(['historical_paid_n' => 1]);
        $this->vote($item);
        $this->vote($item);

        $this->artisan('waitlist:process')->assertSuccessful();

        $item->refresh();
        $this->assertSame(CourseWaitlistItem::STATUS_PAYMENT_OPEN, $item->status);
        $this->assertSame(1, $this->activeTariffs($item));
    }

    public function test_cron_keeps_forecast_gate_without_bound_course(): void
    {
        $item = CourseWaitlistItem::create([
            'slug' => 'unbound-'.uniqid(),
            'course_title' => 'Тест',
            'teacher_name' => 'Тест',
            'min_payers' => 2,
            'kind' => 'other',
            'status' => CourseWaitlistItem::STATUS_COLLECTING,
        ]);
        $this->vote($item);
        $this->vote($item);

        $this->artisan('waitlist:process')->assertSuccessful();

        $item->refresh();
        $this->assertSame(CourseWaitlistItem::STATUS_COLLECTING, $item->status);
    }

    public function test_cron_flag_off_falls_back_to_forecast_gate(): void
    {
        config(['features.waitlist_auto_payment' => false]);
        $item = $this->boundItem(minPayers: 2);
        $this->makeTariffs($item, 1);
        $item->update(['historical_paid_n' => 1]); // прогноз < порога
        $this->vote($item);
        $this->vote($item);

        $this->artisan('waitlist:process')->assertSuccessful();

        // Флаг OFF — прежнее поведение: чистый порог не открывает.
        $item->refresh();
        $this->assertSame(CourseWaitlistItem::STATUS_COLLECTING, $item->status);
        $this->assertSame(0, $this->activeTariffs($item));
    }

    // ================= флаг =================

    public function test_flag_off_disables_auto_open_on_vote(): void
    {
        config(['features.waitlist_auto_payment' => false]);
        $item = $this->boundItem(minPayers: 1);
        $this->makeTariffs($item, 2);

        $this->webVote($item->slug);

        $item->refresh();
        $this->assertSame(CourseWaitlistItem::STATUS_COLLECTING, $item->status);
        $this->assertSame(0, $this->activeTariffs($item));
        $this->assertSame(0, $this->pushedTexts()->count());
    }
}
