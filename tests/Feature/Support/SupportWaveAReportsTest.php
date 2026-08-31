<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H3765 A1 + A4: обе переписи — читающие, и главное, что здесь проверяется, —
 * что вердикт выводится из чисел, а не из настроения. Подсовываем перепись
 * заведомо «историческую» и заведомо «тормозящую» и требуем разных вердиктов.
 */
class SupportWaveAReportsTest extends TestCase
{
    use RefreshDatabase;

    private TelegramSupportAccount $account;

    private TelegramSupportChat $chat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = TelegramSupportAccount::firstOrCreate(['name' => 'support']);
        $this->chat = TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => 9701],
            ['last_message_at' => now()],
        );
    }

    private function message(string $direction, \DateTimeInterface $sentAt, \DateTimeInterface $createdAt, string $text = 'вопрос'): TelegramSupportMessage
    {
        $message = TelegramSupportMessage::create([
            'telegram_support_account_id' => $this->account->id,
            'telegram_support_chat_id' => $this->chat->id,
            'telegram_chat_id' => 9701,
            'telegram_message_id' => random_int(1, 1_000_000),
            'direction' => $direction,
            'text' => $text,
            'sent_at' => $sentAt,
        ]);

        // created_at ставим вручную: именно расхождение sent_at и created_at и
        // есть предмет измерения.
        $message->forceFill(['created_at' => $createdAt])->save();

        return $message->refresh();
    }

    /**
     * Гоняем команду с --write во временный файл и возвращаем написанное.
     * Консольный вывод обеих переписей — одна многострочная строка, и
     * построчный expectsOutputToContain на ней ненадёжен; артефакт всё равно
     * и есть продукт команды.
     *
     * @param  array<string, mixed>  $options
     */
    private function runReport(string $command, array $options = []): string
    {
        $path = sys_get_temp_dir().'/h3765-'.uniqid().'.md';

        $this->artisan($command, $options + ['--write' => $path])->assertSuccessful();

        $this->assertFileExists($path);
        $report = (string) file_get_contents($path);
        @unlink($path);

        return $report;
    }

    private function staleSkip(TelegramSupportMessage $message, \DateTimeInterface $at): void
    {
        $event = SupportAiReplyEvent::create([
            'telegram_support_message_id' => $message->id,
            'event_type' => SupportDmAutoReply::EVENT_STALE_SKIP,
            'meta' => ['via' => SupportDmAutoReply::VIA],
        ]);
        $event->forceFill(['created_at' => $at])->save();
    }

    public function test_latency_report_names_history_backfill_when_the_pipeline_keeps_up(): void
    {
        // Год пролежало у Telegram, обработали мгновенно после приёма.
        for ($i = 0; $i < 5; $i++) {
            $ingestedAt = now()->subDays(2);
            $message = $this->message('incoming', now()->subDays(365), $ingestedAt);
            $this->staleSkip($message, $ingestedAt);
        }

        $this->assertStringContainsString(
            'ДОЗАБОР ИСТОРИИ ДИАЛОГОВ',
            $this->runReport('support:ingest-latency-report', ['--days' => 30]),
        );
    }

    public function test_latency_report_names_the_sync_cadence_when_we_are_the_slow_half(): void
    {
        // Приняли почти сразу, а обработали через двое суток — виноваты мы.
        for ($i = 0; $i < 5; $i++) {
            $message = $this->message('incoming', now()->subDays(3), now()->subDays(3));
            $this->staleSkip($message, now()->subDay());
        }

        $this->assertStringContainsString(
            'ТАКТ СИНКА',
            $this->runReport('support:ingest-latency-report', ['--days' => 30]),
        );
    }

    public function test_shadow_report_on_empty_data_refuses_to_look_conclusive(): void
    {
        $this->assertStringContainsString(
            'Живое включение автоотправки на пустом отчёте недопустимо',
            $this->runReport('support:shadow-report', ['--days' => 7]),
        );
    }

    public function test_shadow_report_joins_the_curators_actual_reply(): void
    {
        $student = User::factory()->create();
        $incoming = $this->message('incoming', now()->subHours(3), now()->subHours(3));

        $event = SupportAiReplyEvent::create([
            'telegram_support_message_id' => $incoming->id,
            'event_type' => SupportDmAutoReply::EVENT_SHADOW_WOULD_SEND,
            'meta' => [
                'via' => SupportDmAutoReply::VIA,
                'category' => 'B',
                'score' => 12.5,
                'chunk_id' => 'политика-и-поддержка/записи-уроков-и-пропуски',
                'draft' => 'Записи занятий появляются в личном кабинете в течение суток после урока.',
                'question_hash' => hash('sha256', 'вопрос'),
                'user_id' => $student->id,
                'telegram_chat_id' => 9701,
            ],
        ]);
        $event->forceFill(['created_at' => now()->subHours(3)])->save();

        // Куратор ответил примерно то же самое через час.
        $this->message('outgoing', now()->subHours(2), now()->subHours(2),
            'Записи занятий появляются в личном кабинете в течение суток после урока, посмотрите там.');

        // Читаем записанный отчёт, а не консоль: заодно проверяем путь --write.
        $report = $this->runReport('support:shadow-report', ['--days' => 7]);

        $this->assertStringContainsString('По полосам BM25-скора', $report);
        // Скор 12.5 → полоса 12–15; куратор ответил и сказал примерно то же.
        $this->assertStringContainsString('| d  12–15 | 1 | 1 | 1 | 100 % |', $report);
        $this->assertStringContainsString('| B | 1 | 1 | 1 | 100 % |', $report);
    }

    public function test_shadow_report_counts_curator_silence(): void
    {
        $student = User::factory()->create();
        $incoming = $this->message('incoming', now()->subHours(3), now()->subHours(3));

        $event = SupportAiReplyEvent::create([
            'telegram_support_message_id' => $incoming->id,
            'event_type' => SupportDmAutoReply::EVENT_SHADOW_WOULD_SEND,
            'meta' => [
                'via' => SupportDmAutoReply::VIA,
                'category' => 'B',
                'score' => 9.0,
                'chunk_id' => 'x',
                'draft' => 'Записи занятий появляются в личном кабинете.',
                'question_hash' => hash('sha256', 'вопрос'),
                'user_id' => $student->id,
                'telegram_chat_id' => 9701,
            ],
        ]);
        $event->forceFill(['created_at' => now()->subHours(3)])->save();

        // Куратор не ответил вовсе.
        $this->assertStringContainsString(
            'В **1** случаях из 1',
            $this->runReport('support:shadow-report', ['--days' => 7]),
        );
    }
}
