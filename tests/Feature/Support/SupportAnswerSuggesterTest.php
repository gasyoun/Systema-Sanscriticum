<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\ChatMessage;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\SupportAnswerSuggestion;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportAnswerEventLogger;
use App\Services\Support\SupportAnswerSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportAnswerSuggesterTest extends TestCase
{
    use RefreshDatabase;

    private function enableFeature(): void
    {
        config(['features.support_answer_suggester' => true]);
        MarketingSetting::create(['support_answer_suggester_enabled' => true]);
        MarketingSetting::flushCached();
    }

    private function studentInGroup(): array
    {
        $user = User::factory()->create();
        $group = Group::create(['name' => 'Группа 1']);
        $user->groups()->attach($group->id);

        return [$user, $group];
    }

    private function futureClass(Group $group, ?string $link = 'https://zoom.us/j/12345'): Schedule
    {
        return Schedule::create([
            'title' => 'Йога-сутры',
            'start' => now()->addDay(),
            'link' => $link,
            'group_id' => $group->id,
        ]);
    }

    private function webMessage(User $user, string $text): ChatMessage
    {
        return ChatMessage::create([
            'user_id' => $user->id,
            'role' => 'user',
            'text' => $text,
            'is_read' => false,
        ]);
    }

    /** Гостевое (анонимное) сообщение веб-виджета (H1198) — user_id = NULL. */
    private function guestMessage(string $text): ChatMessage
    {
        return ChatMessage::create([
            'user_id' => null,
            'role' => 'user',
            'text' => $text,
            'is_read' => false,
        ]);
    }

    private function visibleCourseWithTariff(int $price = 15000): Course
    {
        $course = Course::factory()->create();
        $course->tariffs()->create(['title' => 'Весь курс', 'type' => 'full', 'price' => $price, 'is_active' => true]);

        return $course;
    }

    public function test_categorize_maps_keywords_to_categories(): void
    {
        $suggester = app(SupportAnswerSuggester::class);

        $this->assertSame(SupportAnswerSuggestion::CATEGORY_ZOOM, $suggester->categorize('Дайте ссылку на подключение к занятию'));
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_RECORDING, $suggester->categorize('Где посмотреть запись урока?'));
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_SCHEDULE, $suggester->categorize('Когда следующее занятие?'));
        // «Ссылка на запись» — категория B (записи), а не A: порядок правил важен.
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_RECORDING, $suggester->categorize('Скиньте ссылку на запись'));
        // v2 (S5): «сколько стоит» теперь распознаётся как D (оплата/цена).
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_PAYMENT, $suggester->categorize('Сколько стоит курс?'));
        $this->assertNull($suggester->categorize('Здравствуйте, спасибо за урок!'));
        $this->assertNull($suggester->categorize(''));
    }

    public function test_unrelated_message_creates_no_suggestion(): void
    {
        $this->enableFeature();
        [$user] = $this->studentInGroup();
        $this->webMessage($user, 'Здравствуйте, спасибо большое за урок!');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('support_answer_suggestions', 0);
    }

    public function test_zoom_question_creates_draft_with_next_class_link(): void
    {
        $this->enableFeature();
        [$user, $group] = $this->studentInGroup();
        $this->futureClass($group);
        $this->webMessage($user, 'Есть ссылка на ближайшее занятие?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']);
        $suggestion = SupportAnswerSuggestion::first();
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_ZOOM, $suggestion->category);
        $this->assertStringContainsString('https://zoom.us/j/12345', $suggestion->draft_text);
        $this->assertSame('zoom', $suggestion->facts['type']);
    }

    public function test_schedule_question_creates_draft_with_upcoming_classes(): void
    {
        $this->enableFeature();
        [$user, $group] = $this->studentInGroup();
        $this->futureClass($group, null);
        $this->webMessage($user, 'Подскажите, когда следующее занятие по расписанию?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']);
        $suggestion = SupportAnswerSuggestion::first();
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_SCHEDULE, $suggestion->category);
        $this->assertStringContainsString('Йога-сутры', $suggestion->draft_text);
    }

    public function test_recording_question_creates_draft_with_lesson_url(): void
    {
        $this->enableFeature();
        [$user, $group] = $this->studentInGroup();
        $course = Course::factory()->create();
        Lesson::create([
            'title' => 'Урок 3',
            'is_published' => true,
            'youtube_url' => 'https://youtu.be/abc123',
            'course_id' => $course->id,
            'group_id' => $group->id,
            'lesson_date' => now()->subDay(),
            'block_number' => 1,
        ]);
        $this->webMessage($user, 'Гимн Дурге есть в записи?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']);
        $suggestion = SupportAnswerSuggestion::first();
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_RECORDING, $suggestion->category);
        $this->assertStringContainsString('https://youtu.be/abc123', $suggestion->draft_text);
    }

    public function test_category_without_facts_creates_no_suggestion(): void
    {
        $this->enableFeature();
        [$user] = $this->studentInGroup(); // группа без занятий и записей
        $this->webMessage($user, 'Есть ссылка на ближайшее занятие?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('support_answer_suggestions', 0);
    }

    public function test_dedup_does_not_create_second_suggestion_for_same_message(): void
    {
        $this->enableFeature();
        [$user, $group] = $this->studentInGroup();
        $this->futureClass($group);
        $this->webMessage($user, 'Есть ссылка на ближайшее занятие?');

        app(SupportAnswerSuggester::class)->run();
        app(SupportAnswerSuggester::class)->run();

        $this->assertDatabaseCount('support_answer_suggestions', 1);
    }

    public function test_telegram_incoming_message_is_scanned_too(): void
    {
        $this->enableFeature();
        [$user, $group] = $this->studentInGroup();
        $this->futureClass($group);

        $account = TelegramSupportAccount::create(['name' => 'support']);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => 9900, 'linked_user_id' => $user->id]);
        TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => 9900,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'text' => 'Скиньте ссылку на зум, пожалуйста',
            'sent_at' => now(),
        ]);

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('support_answer_suggestions', [
            'user_id' => $user->id,
            'source_type' => SupportAnswerSuggestion::SOURCE_TELEGRAM_SUPPORT_MESSAGE,
        ]);
    }

    public function test_disabled_admin_toggle_skips_scan_entirely(): void
    {
        config(['features.support_answer_suggester' => true]);
        MarketingSetting::create(['support_answer_suggester_enabled' => false]);
        MarketingSetting::flushCached();
        [$user, $group] = $this->studentInGroup();
        $this->futureClass($group);
        $this->webMessage($user, 'Есть ссылка на ближайшее занятие?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(0, $result['scanned']);
        $this->assertDatabaseCount('support_answer_suggestions', 0);
    }

    public function test_deploy_flag_off_skips_scan_even_when_admin_toggle_on(): void
    {
        config(['features.support_answer_suggester' => false]);
        MarketingSetting::create(['support_answer_suggester_enabled' => true]);
        MarketingSetting::flushCached();
        [$user, $group] = $this->studentInGroup();
        $this->futureClass($group);
        $this->webMessage($user, 'Есть ссылка на ближайшее занятие?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(0, $result['scanned']);
        $this->assertDatabaseCount('support_answer_suggestions', 0);
    }

    public function test_suggested_event_is_logged(): void
    {
        $this->enableFeature();
        [$user, $group] = $this->studentInGroup();
        $this->futureClass($group);
        $this->webMessage($user, 'Есть ссылка на ближайшее занятие?');

        app(SupportAnswerSuggester::class)->run();

        $this->assertDatabaseHas('support_ai_reply_events', [
            'event_type' => SupportAnswerEventLogger::EVENT_SUGGESTED,
        ]);
    }

    // --- H1198 (Jivo-паритет S3): гостевой веб-тред — только категория D. ---

    public function test_guest_payment_question_creates_public_pricing_draft(): void
    {
        $this->enableFeature();
        $this->visibleCourseWithTariff(15000);
        $this->guestMessage('Сколько стоит курс?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(1, $result['created']);
        $suggestion = SupportAnswerSuggestion::first();
        $this->assertNull($suggestion->user_id);
        $this->assertSame(SupportAnswerSuggestion::CATEGORY_PAYMENT, $suggestion->category);
        $this->assertStringContainsString('15 000', $suggestion->draft_text);
        $this->assertSame('public_pricing', $suggestion->facts['type']);
    }

    public function test_guest_non_payment_question_creates_no_suggestion(): void
    {
        // A/B/C/E/F требуют личного зачисления (группа/расписание) — у гостя
        // его в принципе нет, черновик не строится ни при каких данных.
        $this->enableFeature();
        $this->guestMessage('Есть ссылка на ближайшее занятие?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('support_answer_suggestions', 0);
    }

    public function test_guest_payment_question_with_no_visible_courses_creates_no_suggestion(): void
    {
        $this->enableFeature();
        Course::factory()->hidden()->create()->tariffs()->create(['title' => 'X', 'type' => 'full', 'price' => 9000, 'is_active' => true]);
        $this->guestMessage('Сколько стоит курс?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(0, $result['created']);
        $this->assertDatabaseCount('support_answer_suggestions', 0);
    }

    public function test_guest_and_student_suggestions_from_the_same_scan_do_not_collide(): void
    {
        $this->enableFeature();
        $this->visibleCourseWithTariff(15000);
        [$user, $group] = $this->studentInGroup();
        $this->futureClass($group);
        $this->webMessage($user, 'Есть ссылка на ближайшее занятие?');
        $this->guestMessage('Сколько стоит курс?');

        $result = app(SupportAnswerSuggester::class)->run();

        $this->assertSame(2, $result['created']);
        $this->assertDatabaseHas('support_answer_suggestions', ['user_id' => $user->id, 'category' => SupportAnswerSuggestion::CATEGORY_ZOOM]);
        $this->assertDatabaseHas('support_answer_suggestions', ['user_id' => null, 'category' => SupportAnswerSuggestion::CATEGORY_PAYMENT]);
    }
}
