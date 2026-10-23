<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\SupportAiReplyEvent;
use App\Models\Tariff;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Диалоги пилота 23-10-2026 с НАСТОЯЩЕЙ локальной моделью (qwen3 в Ollama).
 * Не для CI: нужен узел Ollama, запуск —
 *   PAY_LLM_LIVE=1 [PAY_LLM_URL=http://127.0.0.1:11434] php artisan test --filter=PayDialogLiveModelTest
 * Данные повторяют кабинет пилота: свои группы (вт, вс, ср) и группы в
 * наборе (две по понедельникам, санкхья без тарифа «весь курс»).
 */
class PayDialogLiveModelTest extends TestCase
{
    use RefreshDatabase;

    private const TG = 908572509;

    private TelegramSupportAccount $account;

    private TelegramSupportChat $chat;

    private User $user;

    /** @var array<string, Group> */
    private array $g = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! getenv('PAY_LLM_LIVE')) {
            $this->markTestSkipped('Живая модель: PAY_LLM_LIVE=1.');
        }

        Carbon::setTestNow('2026-10-23 13:00:00');
        Queue::fake();
        Mail::fake();
        // Только Telegram подменён — запросы к Ollama уходят на настоящий узел.
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_pay_clarify' => true,
            'features.support_enroll_pay_link' => true,
            'features.support_pay_dialog_llm' => getenv('PAY_LLM_MODE') ?: 'live',
            'knowledge.generation_base_url' => getenv('PAY_LLM_URL') ?: 'http://127.0.0.1:11434',
            'knowledge.generation_model' => 'qwen3:14b',
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
            'services.telegram_support.sufler_max_steps_per_ticket' => 100,
        ]);

        $this->user = User::factory()->create();

        $this->g['63'] = $this->group('Грамматика по Кочергиной гр.63', 'forming', 2, 8, 8000, 4);
        $this->g['58'] = $this->group('Грамматика по Кочергиной гр.58', 'active', 7, 14, 6000, 12);
        $this->g['62'] = $this->group('Грамматика по Кочергиной гр.62', 'active', 3, 20, 6000, 3);
        $this->g['61'] = $this->group('Грамматика по Кочергиной гр.61', 'forming', 1, 8, 8000, 4);
        $this->g['upan'] = $this->group('Традиции толкования упанишад (2 поток, 2026)', 'forming', 1, 20, 7000, 4, full: 25000);
        $this->g['sankhya'] = $this->group('Философия санкхьи', 'forming', 4, 19, 6000, 4);
        $this->g['53'] = $this->group('Грамматика по Кочергиной гр.53', 'active', 1, 10, 6000, 6);
        $this->g['sankhyaMon'] = $this->group('Философия санкхьи — пн 18:00 (2026)', 'forming', 1, 18, 6000, 4);
        $this->g['hindi5'] = $this->group('Грамматика хинди гр. 5, вт', 'forming', 1, 9, 5000, 4);

        foreach (['63', '58', '62', 'sankhyaMon', 'hindi5'] as $own) {
            $this->user->activeGroups()->attach($this->g[$own]->id);
        }
        foreach (range(1, 10) as $n) {
            $this->paid($this->g['58'], $n);
        }
        $this->paid($this->g['62'], 1);
        Payment::create(['user_id' => $this->user->id, 'course_id' => $this->g['sankhyaMon']->courses()->first()->id, 'amount' => 1, 'tariff' => 'full', 'status' => 'paid']);

        $this->account = TelegramSupportAccount::create(['name' => 'telegram-business', 'auto_reply_enabled' => true]);
        $this->chat = TelegramSupportChat::create([
            'telegram_chat_id' => self::TG,
            'type' => 'private',
            'linked_user_id' => $this->user->id,
            'last_message_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function group(string $title, string $status, int $isoDay, int $hour, int $price, int $blocks, ?int $full = null): Group
    {
        $course = Course::factory()->create(['title' => $title, 'is_active' => true]);
        foreach (range(1, $blocks) as $n) {
            Tariff::factory()->for($course)->block($n)->create(['price' => $price]);
        }
        if ($full !== null) {
            Tariff::factory()->for($course)->create(['price' => $full]);
        }

        $group = Group::create(['name' => $title, 'status' => $status]);
        $course->groups()->attach($group->id);

        $start = Carbon::parse('2026-10-26 00:00')->startOfWeek()->addDays($isoDay - 1)->setTime($hour, 0);
        foreach ([-7, 0, 7] as $shift) {
            Schedule::create(['title' => $title, 'start' => $start->copy()->addDays($shift), 'end' => $start->copy()->addDays($shift)->addHours(2), 'group_id' => $group->id, 'course_id' => $course->id]);
        }

        return $group;
    }

    private function paid(Group $group, int $block): void
    {
        Payment::create(['user_id' => $this->user->id, 'course_id' => $group->courses()->first()->id, 'amount' => 1, 'tariff' => 'block_'.$block, 'status' => 'paid']);
    }

    private function say(string $text): string
    {
        $before = TelegramSupportMessage::query()->max('id') ?? 0;
        $incoming = TelegramSupportMessage::create([
            'telegram_support_account_id' => $this->account->id,
            'telegram_support_chat_id' => $this->chat->id,
            'telegram_chat_id' => self::TG,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);

        $t0 = microtime(true);
        app(SupportDmAutoReply::class)->handle($incoming, $this->user->id, 'private');
        $took = round(microtime(true) - $t0, 1);

        $reply = (string) TelegramSupportMessage::query()->where('id', '>', $incoming->id)
            ->where('direction', 'outgoing')->orderByDesc('id')->value('text');
        $llm = SupportAiReplyEvent::query()->where('telegram_support_message_id', $incoming->id)
            ->where('event_type', SupportDmAutoReply::EVENT_PAY_LLM_SHADOW)->value('meta');

        fwrite(STDERR, "\n  студент: {$text}\n  бот ({$took} с): ".($reply === '' ? '— ТИШИНА —' : str_replace("\n", "\n        ", $reply))
            .($llm !== null ? "\n  модель: ".json_encode($llm['llm'] ?? null, JSON_UNESCAPED_UNICODE) : '')."\n");

        Carbon::setTestNow(now()->addMinutes(2));

        return $reply;
    }

    private function newConversation(): void
    {
        Carbon::setTestNow(now()->addHours(3));
        fwrite(STDERR, "\n  ─────────────\n");
    }

    public function test_pilot_dialogs(): void
    {
        // Скриншот 23-10-2026 14:44 дословно.
        $r = $this->say('Хочу оплатить, подскажите как');
        $r = $this->say('группа по понедельникам');
        $this->assertStringContainsString('гр.61', $r);
        $this->assertStringContainsString('гр.53', $r);
        $this->assertStringNotContainsString('санкхьи — пн', $r);
        $this->assertStringNotContainsString('хинди', $r);
        $r = $this->say('нет, другая по грамматике');
        $this->assertStringNotContainsString('упанишад', $r);
        $this->assertMatchesRegularExpression('/гр\.(53|61)/u', $r);
        $r = $this->say('61');
        $this->assertStringContainsString('блока 1 группы «Грамматика по Кочергиной гр.61»', $r);

        $this->newConversation();
        $r = $this->say('Хочу оплатить, подскажите как');
        $this->assertStringContainsString('что хотите оплатить', $r);
        $this->assertStringNotContainsString('₽', $r);

        $r = $this->say('давайте тот, что по понедельникам');
        $this->assertStringContainsString('гр.61', $r);
        $this->assertStringContainsString('упанишад', $r);

        $r = $this->say('упанишады, второй блок');
        $this->assertStringContainsString('блока 2 группы «Традиции толкования упанишад', $r);

        $this->newConversation();
        $r = $this->say('а можно сразу за весь курс санкхьи?');
        $this->assertStringContainsString('Весь курс сразу здесь оплатить нельзя', $r);
        $r = $this->say('второй');
        $this->assertStringContainsString('блока 2 группы «Философия санкхьи»', $r);

        $this->newConversation();
        $r = $this->say('не проходит оплата в личном кабинете, карта отклоняется');
        $this->assertStringContainsString('что хотите оплатить', $r);
        $r = $this->say('гр.63');
        $this->assertStringContainsString('блока 1 группы «Грамматика по Кочергиной гр.63»', $r);
        $this->assertStringContainsString('снимок экрана', $r);

        $r = $this->say('дороговато, можно в рассрочку или со скидкой?');
        $this->assertStringContainsString('Передаю ваш вопрос куратору', $r);

        $this->newConversation();
        $r = $this->say('мне бы оплатить грамматику, ту что по воскресеньям');
        $this->assertStringContainsString('блока 11 группы «Грамматика по Кочергиной гр.58»', $r);

        $this->newConversation();
        $r = $this->say('хочу оплатить следующий блок');
        $this->assertStringContainsString('что хотите оплатить', $r);
        $r = $this->say('ну ту, где я по средам занимаюсь');
        $this->assertStringContainsString('блока 2 группы «Грамматика по Кочергиной гр.62»', $r);

        $this->newConversation();
        $r = $this->say('как оплатить?');
        // Не ответ на вопрос об оплате — это общий конвейер (FAQ / куратор),
        // ссылку наугад не шлём, вопрос об оплате остаётся открытым.
        $r = $this->say('не знаю точно, посоветуйте что-нибудь для начинающих');
        $this->assertStringNotContainsString('Ссылка на оплату', $r);
    }
}
