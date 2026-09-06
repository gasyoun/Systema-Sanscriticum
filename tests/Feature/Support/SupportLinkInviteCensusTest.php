<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportContact;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmLinkInvite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3999 (шаг I3): недельный список контактов для РУЧНОЙ привязки.
 *
 * Мерит потолок волны 1 — сколько вопросов резолверы фактов не решат в
 * принципе, потому что не знают, о ком речь. Числитель без знаменателя здесь
 * запрещён (рулинг I4), и сопоставления по телефону или @username нет нигде:
 * ошибка сопоставления ответила бы одному студенту остатком другого.
 */
class SupportLinkInviteCensusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-06 12:00:00');

        config([
            'services.telegram.bot_token' => 'test-token',
            'services.telegram.admin_id' => '111',
        ]);

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Контакт без связи с кабинетом и его входящие. */
    private function unlinkedContact(int $chatId, int $messages, string $name = 'Аноним'): TelegramSupportContact
    {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);

        $chat = TelegramSupportChat::create([
            'telegram_chat_id' => $chatId,
            'type' => 'private',
            'last_message_at' => now()->subHours(2),
        ]);

        $contact = TelegramSupportContact::create([
            'telegram_user_id' => $chatId,
            'telegram_support_chat_id' => $chat->id,
            'linked_user_id' => null,
            'name' => $name,
        ]);

        for ($i = 0; $i < $messages; $i++) {
            TelegramSupportMessage::create([
                'telegram_support_account_id' => $account->id,
                'telegram_support_chat_id' => $chat->id,
                'telegram_chat_id' => $chatId,
                'telegram_message_id' => random_int(1, 1_000_000),
                'direction' => 'incoming',
                'text' => 'здравствуйте, вопрос номер '.($i + 1),
                'sent_at' => now()->subHours($i + 1),
            ]);
        }

        return $contact;
    }

    public function test_only_contacts_at_or_above_the_threshold_are_listed(): void
    {
        $this->unlinkedContact(7101, 3, 'Трижды писал');
        $this->unlinkedContact(7102, 1, 'Написал однажды');

        $rows = app(SupportDmLinkInvite::class)->unlinkedWithMessages(7, 2);

        $this->assertCount(1, $rows);
        $this->assertSame(7101, $rows[0]['chat_id']);
        $this->assertSame(3, $rows[0]['messages']);
    }

    public function test_a_linked_contact_is_out_of_scope(): void
    {
        $contact = $this->unlinkedContact(7103, 4);
        $student = User::factory()->create();
        $contact->forceFill(['linked_user_id' => $student->id])->save();

        $this->assertSame([], app(SupportDmLinkInvite::class)->unlinkedWithMessages(7, 2));
    }

    public function test_rows_are_sorted_by_message_count(): void
    {
        $this->unlinkedContact(7104, 2);
        $this->unlinkedContact(7105, 5);

        $rows = app(SupportDmLinkInvite::class)->unlinkedWithMessages(7, 2);

        $this->assertSame([7105, 7104], array_column($rows, 'chat_id'));
    }

    public function test_the_report_prints_the_numerator_next_to_its_denominator(): void
    {
        $this->unlinkedContact(7106, 3);
        $this->unlinkedContact(7107, 1);

        \Illuminate\Support\Facades\Artisan::call('support:link-invite-census', ['--dry' => true]);
        $out = \Illuminate\Support\Facades\Artisan::output();

        // Числитель и знаменатель печатаются рядом (рулинг I4): «1 контакт»
        // без «из скольких» — это впечатление, а не метрика.
        $this->assertStringContainsString('Всего без связи с кабинетом: 2', $out);
        $this->assertStringContainsString('Из них написали ≥2 раз: 1', $out);

        Http::assertNothingSent();
    }

    public function test_the_report_names_chats_and_never_people(): void
    {
        $this->unlinkedContact(7108, 3, 'Мария Петрова');

        $this->artisan('support:link-invite-census')->assertExitCode(0);

        Http::assertSent(function ($request): bool {
            $text = (string) ($request['text'] ?? '');

            if (! str_contains($text, 'Незалинкованные контакты')) {
                return false;
            }

            // Идентификатора чата достаточно, чтобы открыть диалог; имя,
            // @username и телефон в сводке не появляются никогда.
            return str_contains($text, 'чат 7108')
                && ! str_contains($text, 'Мария');
        });
    }

    public function test_an_empty_census_says_so_instead_of_printing_a_bare_zero(): void
    {
        $this->artisan('support:link-invite-census', ['--dry' => true])
            ->expectsOutputToContain('Привязывать вручную некого.')
            ->assertExitCode(0);
    }
}
