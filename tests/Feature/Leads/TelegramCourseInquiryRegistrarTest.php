<?php

declare(strict_types=1);

namespace Tests\Feature\Leads;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Services\Leads\TelegramCourseInquiryRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramCourseInquiryRegistrarTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_enrolment_question_creates_one_lead_and_auditable_note(): void
    {
        $message = $this->incoming('Хочу записаться на грамматику, суббота в 12:00. ГРАММАТИКА-СВАМИ');

        $registrar = app(TelegramCourseInquiryRegistrar::class);
        $lead = $registrar->register($message->load(['chat', 'contact']));
        $registrar->register($message->fresh()->load(['chat', 'contact']));

        $this->assertNotNull($lead);
        $this->assertDatabaseHas('leads', [
            'id' => $lead->id,
            'telegram_chat_id' => 77101,
            'utm_source' => 'india_swami',
            'utm_campaign' => 'grammar_gasuns_2026_09',
        ]);
        $this->assertDatabaseCount('leads', 1);
        $this->assertDatabaseCount('lead_notes', 1);
    }

    public function test_ordinary_course_discussion_does_not_create_a_lead(): void
    {
        $message = $this->incoming('Вчерашняя грамматика была трудной, посмотрю запись позже.');

        $this->assertNull(app(TelegramCourseInquiryRegistrar::class)->register($message->load(['chat', 'contact'])));
        $this->assertDatabaseCount('leads', 0);
    }

    private function incoming(string $text): TelegramSupportMessage
    {
        $account = TelegramSupportAccount::create(['name' => 'support', 'is_enabled' => true]);
        $chat = TelegramSupportChat::create(['telegram_chat_id' => 77101, 'type' => 'private']);
        $contact = TelegramSupportContact::create([
            'telegram_user_id' => 88001,
            'telegram_support_chat_id' => $chat->id,
            'name' => 'Тестовый человек',
            'username' => 'test_person',
        ]);

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_support_contact_id' => $contact->id,
            'telegram_chat_id' => 77101,
            'telegram_message_id' => 1,
            'direction' => 'incoming',
            'role' => 'user',
            'text' => $text,
            'sent_at' => now(),
        ]);
    }
}
