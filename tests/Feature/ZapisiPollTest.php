<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ZapisiBot\ZapisiPolls;
use App\Filament\Resources\GroupResource\Pages\ListGroups;
use App\Jobs\ProcessTelegramZapisiUpdate;
use App\Jobs\SendZapisiPollJob;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\SocialAccount;
use App\Models\TelegramPoll;
use App\Models\TelegramPollAnswer;
use App\Models\User;
use App\Services\Telegram\ZapisiPollService;
use App\Support\Roles;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Опросы @zapisi_ORSbot в чатах групп: создание, отправка (sendPoll, не
 * анонимно), приём голосов poll_answer поимённо, закрытие, админка.
 */
class ZapisiPollTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT_ID = '-100555';

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.telegram_zapisi_bot' => true]);
        MarketingSetting::create(['zapisi_bot_username' => 'zapisi_ORSbot', 'zapisi_bot_token' => 'ZAPISI-TOKEN']);
        MarketingSetting::flushCached();
    }

    private function group(?string $chatId = self::CHAT_ID, string $name = 'Грамматика гр.7'): Group
    {
        return Group::create(['name' => $name, 'telegram_chat_id' => $chatId]);
    }

    private function sentPoll(Group $group, array $options = ['Да', 'Нет', 'Пока не знаю']): TelegramPoll
    {
        return TelegramPoll::create([
            'group_id' => $group->id,
            'chat_id' => (string) $group->telegram_chat_id,
            'question' => 'Идёте дальше?',
            'options' => $options,
            'status' => TelegramPoll::STATUS_SENT,
            'tg_poll_id' => '5550001',
            'message_id' => 77,
            'sent_at' => now(),
        ]);
    }

    private function answer(string $pollId, int $tgUserId, array $optionIds, array $user = []): void
    {
        app(ZapisiPollService::class)->recordAnswer([
            'poll_id' => $pollId,
            'user' => $user + ['id' => $tgUserId, 'is_bot' => false, 'first_name' => 'Анна'],
            'option_ids' => $optionIds,
        ]);
    }

    private function admin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
    }

    // ---- create -------------------------------------------------------------

    public function test_create_stores_pending_poll_and_queues_sending(): void
    {
        Queue::fake();

        $poll = app(ZapisiPollService::class)->create($this->group(), '  Идёте дальше? ', ['Да', ' ', 'Нет'], true);

        $this->assertSame(TelegramPoll::STATUS_PENDING, $poll->status);
        $this->assertSame('Идёте дальше?', $poll->question);
        $this->assertSame(['Да', 'Нет'], $poll->options);
        $this->assertTrue($poll->allows_multiple);
        $this->assertSame(self::CHAT_ID, $poll->chat_id);
        Queue::assertPushed(SendZapisiPollJob::class, fn (SendZapisiPollJob $job): bool => $job->pollId === $poll->id);
    }

    public function test_create_rejects_bad_input(): void
    {
        Queue::fake();
        $service = app(ZapisiPollService::class);
        $group = $this->group();

        foreach ([
            fn () => $service->create($group, 'Вопрос', ['Один'], false),
            fn () => $service->create($group, 'Вопрос', array_map(fn ($i) => "В{$i}", range(1, 11)), false),
            fn () => $service->create($group, '', ['Да', 'Нет'], false),
            fn () => $service->create($group, 'Вопрос', ['Да', str_repeat('я', 101)], false),
            fn () => $service->create($this->group(null, 'Без чата'), 'Вопрос', ['Да', 'Нет'], false),
        ] as $bad) {
            try {
                $bad();
                $this->fail('ожидался InvalidArgumentException');
            } catch (InvalidArgumentException) {
                // ok
            }
        }

        $this->assertSame(0, TelegramPoll::count());
        Queue::assertNothingPushed();
    }

    // ---- sending -------------------------------------------------------------

    private function pendingPoll(): TelegramPoll
    {
        return TelegramPoll::create([
            'group_id' => $this->group()->id,
            'chat_id' => self::CHAT_ID,
            'question' => 'Когда удобнее?',
            'options' => ['Вт', 'Чт'],
            'allows_multiple' => true,
            'status' => TelegramPoll::STATUS_PENDING,
        ]);
    }

    public function test_job_sends_named_poll_and_stores_telegram_ids(): void
    {
        Redis::shouldReceive('set')->once()->andReturn(true);
        Http::fake(['api.telegram.org/*' => Http::response([
            'ok' => true,
            'result' => ['message_id' => 901, 'poll' => ['id' => '6100200300', 'question' => 'Когда удобнее?']],
        ])]);
        $poll = $this->pendingPoll();

        (new SendZapisiPollJob($poll->id))->handle();

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'botZAPISI-TOKEN/sendPoll')
            && $r['chat_id'] === self::CHAT_ID
            && $r['is_anonymous'] === false
            && $r['allows_multiple_answers'] === true
            && $r['options'] === [['text' => 'Вт'], ['text' => 'Чт']]);
        $poll->refresh();
        $this->assertSame(TelegramPoll::STATUS_SENT, $poll->status);
        $this->assertSame('6100200300', $poll->tg_poll_id);
        $this->assertSame(901, (int) $poll->message_id);
        $this->assertNotNull($poll->sent_at);
    }

    public function test_telegram_refusal_releases_claim_marks_failed_and_retries(): void
    {
        $poll = $this->pendingPoll();
        Redis::shouldReceive('set')->once()->andReturn(true);
        Redis::shouldReceive('del')->once()->with('tg:poll:'.$poll->id);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)]);

        try {
            (new SendZapisiPollJob($poll->id))->handle();
            $this->fail('ожидалось исключение для ретрая');
        } catch (\RuntimeException) {
            // бэкофф-ретрай безопасен — Telegram ответил, доставки не было
        }

        $poll->refresh();
        $this->assertSame(TelegramPoll::STATUS_FAILED, $poll->status);
        $this->assertSame('Bad Request: chat not found', $poll->error);
    }

    public function test_lost_response_keeps_claim_and_marks_unknown(): void
    {
        Redis::shouldReceive('set')->once()->andReturn(true);
        Redis::shouldReceive('del')->never();
        Http::fake(['api.telegram.org/*' => Http::failedConnection()]);
        $poll = $this->pendingPoll();

        (new SendZapisiPollJob($poll->id))->handle();

        $this->assertSame(TelegramPoll::STATUS_UNKNOWN, $poll->fresh()->status);
    }

    public function test_job_skips_already_sent_poll(): void
    {
        Http::fake();
        $poll = $this->sentPoll($this->group());

        (new SendZapisiPollJob($poll->id))->handle();

        Http::assertNothingSent();
    }

    // ---- answers -------------------------------------------------------------

    public function test_answer_is_matched_to_student_by_telegram_id(): void
    {
        $poll = $this->sentPoll($this->group());
        $student = User::factory()->create(['name' => 'Анна Петрова', 'telegram_id' => 111222]);

        $this->answer('5550001', 111222, [0], ['username' => 'anna_p']);

        $answer = TelegramPollAnswer::sole();
        $this->assertSame($poll->id, $answer->telegram_poll_id);
        $this->assertSame($student->id, $answer->user_id);
        $this->assertSame([0], $answer->option_ids);
        $this->assertSame('anna_p', $answer->tg_username);
        $this->assertSame('Анна Петрова', $answer->displayName());
    }

    public function test_answer_is_matched_via_social_account(): void
    {
        $this->sentPoll($this->group());
        $student = User::factory()->create();
        SocialAccount::create(['user_id' => $student->id, 'provider' => SocialAccount::PROVIDER_TELEGRAM, 'provider_id' => '333444']);

        $this->answer('5550001', 333444, [1]);

        $this->assertSame($student->id, TelegramPollAnswer::sole()->user_id);
    }

    public function test_unlinked_voter_keeps_telegram_name(): void
    {
        $this->sentPoll($this->group());

        $this->answer('5550001', 999, [2], ['first_name' => 'Олег', 'last_name' => 'К.', 'username' => 'oleg_k']);

        $answer = TelegramPollAnswer::sole();
        $this->assertNull($answer->user_id);
        $this->assertSame('Олег К. @oleg_k', $answer->displayName());
    }

    public function test_changed_vote_updates_and_empty_vote_means_retracted(): void
    {
        $poll = $this->sentPoll($this->group());

        $this->answer('5550001', 555, [0]);
        $this->answer('5550001', 555, [1]);
        $this->assertSame([1], TelegramPollAnswer::sole()->option_ids);

        $this->answer('5550001', 555, []);
        $answer = TelegramPollAnswer::sole();
        $this->assertTrue($answer->isRetracted());

        $tally = $poll->optionTally();
        $this->assertSame(0, $tally[0]['count'] + $tally[1]['count'] + $tally[2]['count']);
    }

    public function test_tally_counts_multiple_choice_per_option(): void
    {
        $poll = $this->sentPoll($this->group());

        $this->answer('5550001', 1, [0, 2]);
        $this->answer('5550001', 2, [0]);

        $tally = $poll->optionTally();
        $this->assertSame([2, 0, 1], [$tally[0]['count'], $tally[1]['count'], $tally[2]['count']]);
    }

    public function test_foreign_poll_and_chat_votes_are_ignored(): void
    {
        $this->sentPoll($this->group());

        $this->answer('not-ours', 1, [0]);
        app(ZapisiPollService::class)->recordAnswer(['poll_id' => '5550001', 'voter_chat' => ['id' => -100], 'option_ids' => [0]]);

        $this->assertSame(0, TelegramPollAnswer::count());
    }

    public function test_bot_update_with_poll_answer_is_recorded(): void
    {
        Queue::fake();
        $this->sentPoll($this->group());

        (new ProcessTelegramZapisiUpdate([
            'poll_answer' => ['poll_id' => '5550001', 'user' => ['id' => 4242, 'first_name' => 'Ира'], 'option_ids' => [1]],
        ]))->handle();

        $this->assertSame([1], TelegramPollAnswer::sole()->option_ids);
    }

    // ---- close ---------------------------------------------------------------

    public function test_close_stops_poll_in_chat(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['id' => '5550001', 'is_closed' => true]])]);
        $poll = $this->sentPoll($this->group());

        app(ZapisiPollService::class)->close($poll);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'botZAPISI-TOKEN/stopPoll')
            && $r['chat_id'] === self::CHAT_ID && (int) $r['message_id'] === 77);
        $this->assertSame(TelegramPoll::STATUS_CLOSED, $poll->fresh()->status);
    }

    public function test_close_treats_already_closed_as_success(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: poll has already been closed'], 400)]);
        $poll = $this->sentPoll($this->group());

        app(ZapisiPollService::class)->close($poll);

        $this->assertSame(TelegramPoll::STATUS_CLOSED, $poll->fresh()->status);
    }

    // ---- admin ---------------------------------------------------------------

    public function test_group_button_sends_poll(): void
    {
        $this->admin();
        Queue::fake();
        $group = $this->group();
        $undoRepeaterFake = Repeater::fake(); // числовые ключи вместо UUID у пунктов по умолчанию

        Livewire::test(ListGroups::class)
            ->callTableAction('send_poll_to_chat', $group, data: [
                'question' => 'Переносим занятие на четверг?',
                'options' => [['text' => 'Да'], ['text' => 'Нет']],
                'allows_multiple' => false,
            ])
            ->assertHasNoTableActionErrors();
        $undoRepeaterFake();

        $poll = TelegramPoll::sole();
        $this->assertSame('Переносим занятие на четверг?', $poll->question);
        $this->assertSame(['Да', 'Нет'], $poll->options);
        Queue::assertPushed(SendZapisiPollJob::class);
    }

    public function test_group_button_hidden_without_chat_or_with_bot_off(): void
    {
        $this->admin();
        $noChat = $this->group(null, 'Без чата');
        Livewire::test(ListGroups::class)->assertTableActionHidden('send_poll_to_chat', $noChat);

        config(['features.telegram_zapisi_bot' => false]);
        Livewire::test(ListGroups::class)->assertTableActionHidden('send_poll_to_chat', $this->group());
    }

    public function test_polls_page_creates_poll_and_shows_named_results(): void
    {
        $this->admin();
        Queue::fake();
        $group = $this->group();
        $undoRepeaterFake = Repeater::fake();

        Livewire::test(ZapisiPolls::class)
            ->callAction('new_poll', data: [
                'group_id' => $group->id,
                'question' => 'Удобно в 19:00?',
                'options' => [['text' => 'Да'], ['text' => 'Нет']],
                'allows_multiple' => false,
            ])
            ->assertHasNoActionErrors();
        $undoRepeaterFake();
        $this->assertSame('Удобно в 19:00?', TelegramPoll::sole()->question);

        $poll = $this->sentPoll($group);
        User::factory()->create(['name' => 'Анна Петрова', 'telegram_id' => 111222]);
        $this->answer('5550001', 111222, [0]);

        Livewire::test(ZapisiPolls::class)
            ->assertCanSeeTableRecords([$poll])
            ->mountTableAction('results', $poll)
            ->assertSee('Анна Петрова');
    }

    public function test_polls_page_is_admin_only(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));

        $this->assertFalse(ZapisiPolls::canAccess());
    }
}
