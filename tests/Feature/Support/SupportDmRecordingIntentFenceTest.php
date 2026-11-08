<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Инцидент 01-11-2026 (H7642): на «Потом же можно будет если что в записи
 * приобрести?» бот ответил canned-ссылкой «Запись урока … доступна» на
 * последнюю запись хинди-курса. За 14 дней пять отправок, четыре мимо —
 * покупка записи («приобрести»), запись на курс («записаться на курс»,
 * «можно меня записать»). Здесь запинен забор в коде: recording-факт не
 * уходит студенту при покупочном/записном намерении — вопрос куратору
 * (ровно как денежный забор 19-09-2026 в SupportDmMoneyIntentFenceTest).
 *
 * Контроль ниже доказывает, что легитимная «отправьте ссылку на запись»
 * по-прежнему получает факт: гасит его именно забор намерения, а не
 * сломанная фикстура.
 */
class SupportDmRecordingIntentFenceTest extends TestCase
{
    use RefreshDatabase;

    private const INCIDENT_TEXT = 'Потом же можно будет если что в записи приобрести?';

    private const ENROLL_COURSE_TEXT = 'хочу записаться на курс кашмирского шаваизма';

    private const ENROLL_GROUP_TEXT = 'Можно меня тогда на нее записать?';

    private const RECORDING_REQUEST_TEXT = 'отправьте пожалуйста ссылку на запись занятия';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.support_dm_auto_reply' => true,
            'features.support_dm_auto_reply_live_faq' => false,
            'features.support_auto_reply_templates' => false,
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
            'support.faq_rag.path' => base_path('tests/fixtures/faq_shadow_corpus.md'),
            'support.faq_rag.shadow_min_score' => 1000.0,
            'support.faq_rag.shadow_min_score_by_category' => [],
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        TelegramSupportAccount::query()->create([
            'name' => 'support',
            'is_enabled' => true,
            'auto_reply_enabled' => true,
        ]);
    }

    public function test_control_recording_request_still_gets_the_link(): void
    {
        $user = $this->studentWithRecordedLesson();

        $result = app(SupportDmAutoReply::class)->handle(
            $this->incoming($user, self::RECORDING_REQUEST_TEXT),
            $user->id,
            'private',
        );

        $this->assertSame('sent', $result['status'], 'контроль: легитимная просьба о записи получает факт как раньше');

        $outgoing = TelegramSupportMessage::query()->where('direction', 'outgoing')->first();
        $this->assertNotNull($outgoing);
        $this->assertStringContainsString('https://youtu.be/fence-check', (string) $outgoing->text);

        $sent = SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_SENT)->first();
        $this->assertSame('recording', $sent->meta['fact_type']);
    }

    public function test_purchase_intent_suppresses_the_recording_fact(): void
    {
        $user = $this->studentWithRecordedLesson();

        $result = app(SupportDmAutoReply::class)->handle(
            $this->incoming($user, self::INCIDENT_TEXT),
            $user->id,
            'private',
        );

        // Текст инцидента 01-11-2026 (conv 240): покупочное намерение без
        // денежных слов — денежный забор его не держит, держит recording-й.
        $this->assertSame('hinted', $result['status']);
        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'покупка записи — вопрос куратора, canned-ссылка не уходит',
        );

        $outgoingTexts = TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->pluck('text')
            ->implode("\n");
        $this->assertStringNotContainsString('youtu.be', $outgoingTexts);

        $this->assertSame(
            1,
            SupportAiReplyEvent::query()->where('event_type', SupportDmAutoReply::EVENT_HINTED)->count(),
            'вопрос эскалирован куратору',
        );
    }

    public function test_enroll_on_course_intent_suppresses_the_recording_fact(): void
    {
        $user = $this->studentWithRecordedLesson();

        $result = app(SupportDmAutoReply::class)->handle(
            $this->incoming($user, self::ENROLL_COURSE_TEXT),
            $user->id,
            'private',
        );

        // 30-10-2026 (conv 63): «записаться на курс» — бот отправил запись
        // урока вместо передачи куратору.
        $this->assertSame('hinted', $result['status']);
        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'запись на курс — вопрос куратора',
        );
    }

    public function test_enroll_on_group_intent_suppresses_the_recording_fact(): void
    {
        $user = $this->studentWithRecordedLesson();

        $result = app(SupportDmAutoReply::class)->handle(
            $this->incoming($user, self::ENROLL_GROUP_TEXT),
            $user->id,
            'private',
        );

        // 20-10-2026 (conv 173): «можно меня тогда на нее записать?» —
        // записаться в группу, а не получить ссылку на запись урока.
        $this->assertSame('hinted', $result['status']);
        $this->assertSame(
            0,
            TelegramSupportMessage::query()->where('direction', 'outgoing')->count(),
            'запись в группу — вопрос куратора',
        );
    }

    /** Студент активной группы с опубликованным уроком, у которого есть запись. */
    private function studentWithRecordedLesson(): User
    {
        $course = Course::factory()->create(['title' => 'Продленка санскрита']);
        $group = Group::factory()->create();
        $group->courses()->attach($course->id);

        $student = User::factory()->create();
        $student->groups()->attach($group->id);

        Lesson::factory()->create([
            'course_id' => $course->id,
            'title' => 'Продленка санскрита пт. 9.00 #2',
            'is_published' => true,
            'lesson_date' => now(),
            'youtube_url' => 'https://youtu.be/fence-check',
        ]);

        return $student;
    }

    private function incoming(User $user, string $text): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::query()->where('name', 'support')->firstOrFail();
        $chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9601],
            ['linked_user_id' => $user->id, 'last_message_at' => now()],
        );

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9601,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => 'incoming',
            'text' => $text,
            'sent_at' => now(),
        ]);
    }
}
