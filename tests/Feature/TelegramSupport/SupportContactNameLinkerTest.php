<?php

declare(strict_types=1);

namespace Tests\Feature\TelegramSupport;

use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\User;
use App\Services\TelegramSupport\SupportContactNameLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ручная сшивка по ФИО: только однозначные пары и только по команде человека.
 * Автоматическая сшивка при синхронизации по-прежнему ФИО не использует —
 * это пинит TelegramSupportAnalyticsTest.
 */
class SupportContactNameLinkerTest extends TestCase
{
    use RefreshDatabase;

    private function contact(string $name, int $tgId): TelegramSupportContact
    {
        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => $tgId,
            'type' => 'private',
        ]);

        return TelegramSupportContact::create([
            'telegram_user_id' => $tgId,
            'telegram_support_chat_id' => $chat->id,
            'name' => $name,
        ]);
    }

    /** @test */
    public function links_unambiguous_full_name_with_city_and_word_order(): void
    {
        $user = User::factory()->create(['name' => 'Перевалова Юлия Игоревна']);
        $contact = $this->contact('Юлия Перевалова, Москва', 555001);

        $result = app(SupportContactNameLinker::class)->linkByName();

        $this->assertSame(1, $result['linked']);
        $this->assertSame($user->id, $contact->fresh()->linked_user_id);
        // Сшивка контакта тянет за собой приватный чат — на нём стоит канал.
        $this->assertSame($user->id, $contact->fresh()->chat->linked_user_id);
    }

    /** @test */
    public function two_users_with_same_name_are_not_linked(): void
    {
        User::factory()->create(['name' => 'Романов Павел Сергеевич']);
        User::factory()->create(['name' => 'Романов Павел Сергеевич']);
        $contact = $this->contact('Павел Романов', 555002);

        $result = app(SupportContactNameLinker::class)->linkByName();

        $this->assertSame(0, $result['linked']);
        $this->assertNull($contact->fresh()->linked_user_id);
    }

    /** @test */
    public function single_word_contact_name_is_not_linked(): void
    {
        User::factory()->create(['name' => 'Фролов Артем Владимирович']);
        $contact = $this->contact('Артем', 555003);

        $result = app(SupportContactNameLinker::class)->linkByName();

        $this->assertSame(0, $result['linked']);
        $this->assertNull($contact->fresh()->linked_user_id);
    }

    /** @test */
    public function user_with_another_linked_telegram_account_is_not_relinked(): void
    {
        $user = User::factory()->create(['name' => 'Фролов Артем Владимирович']);

        $existingChat = TelegramSupportChat::create(['telegram_chat_id' => 555010, 'type' => 'private']);
        TelegramSupportContact::create([
            'telegram_user_id' => 555010,
            'telegram_support_chat_id' => $existingChat->id,
            'name' => 'Фролов Артем',
            'linked_user_id' => $user->id,
        ]);

        $contact = $this->contact('Фролов Артем Владимирович', 555011);

        $result = app(SupportContactNameLinker::class)->linkByName();

        $this->assertSame(0, $result['linked']);
        $this->assertNull($contact->fresh()->linked_user_id);
    }

    /** @test */
    public function dry_run_counts_but_does_not_write(): void
    {
        $user = User::factory()->create(['name' => 'Ширяев Алексей Петрович']);
        $contact = $this->contact('Алексей Ширяев', 555004);

        $result = app(SupportContactNameLinker::class)->linkByName(false);

        $this->assertFalse($result['applied']);
        $this->assertSame(1, $result['linked']);
        $this->assertSame($user->id, $result['samples'][0]['user_id']);
        $this->assertNull($contact->fresh()->linked_user_id);
    }

    /** @test */
    public function command_dry_run_writes_nothing_and_apply_writes(): void
    {
        User::factory()->create(['name' => 'Штукатурова Александра Игоревна']);
        $contact = $this->contact('Александра Штукатурова', 555005);

        $this->artisan('telegram-support:link-contacts')->assertExitCode(0);
        $this->assertNull($contact->fresh()->linked_user_id);

        $this->artisan('telegram-support:link-contacts', ['--apply' => true])->assertExitCode(0);
        $this->assertNotNull($contact->fresh()->linked_user_id);
    }
}
