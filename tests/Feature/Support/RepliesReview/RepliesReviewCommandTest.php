<?php

declare(strict_types=1);

namespace Tests\Feature\Support\RepliesReview;

use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H67xx — support:replies-review: seeded-детерминированная stratified выборка
 * ИСХОДЯЩИХ ответов, лист в защищённый storage, наружу только агрегаты;
 * импорт вердиктов ok|minor|wrong|na считает ok-share/Wilson/покрытие;
 * фикстуры синтетические — секретные строки не должны покидать лист.
 */
class RepliesReviewCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $sheet;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-14 12:00:00', 'Europe/Moscow'));
        $this->sheet = storage_path('app/support-replies/test-review-sheet.tsv');
        if (! is_dir(dirname($this->sheet))) {
            mkdir(dirname($this->sheet), 0775, true);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->sheet);
        @unlink($this->sheet.'.manifest.json');
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function chat(int $chatId): TelegramSupportChat
    {
        TelegramSupportAccount::firstOrCreate(['name' => 'support'], ['is_enabled' => true]);

        return TelegramSupportChat::firstOrCreate(
            ['telegram_chat_id' => $chatId],
            ['type' => 'private']
        );
    }

    private function outgoing(
        TelegramSupportChat $chat,
        string $text,
        int $messageId,
        string $sentAt = '2026-10-07 12:00',
        string $via = 'sufler',
        ?string $responderType = 'ai',
        array $rawExtra = [],
    ): TelegramSupportMessage {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support'], ['is_enabled' => true]);

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => $messageId,
            'direction' => 'outgoing',
            'role' => $responderType ?? 'unknown',
            'responder_type' => $responderType,
            'ai_state' => $responderType === 'ai' ? 'sent' : null,
            'text' => $text,
            'raw_payload' => array_merge(['via' => $via], $rawExtra) ?: null,
            'sent_at' => CarbonImmutable::parse($sentAt, 'Europe/Moscow'),
        ]);
    }

    private function incoming(
        TelegramSupportChat $chat,
        string $text,
        int $messageId,
        string $sentAt = '2026-10-07 11:50',
    ): TelegramSupportMessage {
        $account = TelegramSupportAccount::firstOrCreate(['name' => 'support'], ['is_enabled' => true]);

        return TelegramSupportMessage::create([
            'telegram_support_account_id' => $account->id,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            'telegram_message_id' => $messageId,
            'direction' => 'incoming',
            'role' => 'user',
            'text' => $text,
            'sent_at' => CarbonImmutable::parse($sentAt, 'Europe/Moscow'),
        ]);
    }

    /** @return list<int> */
    private function sheetIds(): array
    {
        $ids = [];
        foreach (file($this->sheet, FILE_IGNORE_NEW_LINES) as $i => $line) {
            if ($i === 0) {
                continue;
            }
            $ids[] = (int) explode("\t", $line)[0];
        }

        return $ids;
    }

    public function test_same_seed_rederives_identical_pick(): void
    {
        $chat = $this->chat(41001);
        foreach (range(1, 20) as $i) {
            $this->outgoing($chat, 'ответ №'.$i, 100 + $i, '2026-10-07 12:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        Artisan::call('support:replies-review', ['--week' => '2026-10-05', '--sample' => 6, '--seed' => 1234, '--sheet' => $this->sheet]);
        $first = $this->sheetIds();

        @unlink($this->sheet);
        Artisan::call('support:replies-review', ['--week' => '2026-10-05', '--sample' => 6, '--seed' => 1234, '--sheet' => $this->sheet]);
        $second = $this->sheetIds();

        $this->assertCount(6, $first);
        $this->assertSame($first, $second, 'the same seed+window+size must re-derive the identical pick');
    }

    public function test_different_seed_changes_pick(): void
    {
        $chat = $this->chat(41002);
        foreach (range(1, 20) as $i) {
            $this->outgoing($chat, 'ответ №'.$i, 200 + $i, '2026-10-07 12:'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        Artisan::call('support:replies-review', ['--week' => '2026-10-05', '--sample' => 8, '--seed' => 1234, '--sheet' => $this->sheet]);
        $first = $this->sheetIds();

        @unlink($this->sheet);
        Artisan::call('support:replies-review', ['--week' => '2026-10-05', '--sample' => 8, '--seed' => 9999, '--sheet' => $this->sheet]);
        $second = $this->sheetIds();

        $this->assertNotSame($first, $second);
    }

    public function test_every_nonempty_stratum_is_represented(): void
    {
        $chat = $this->chat(41003);
        // 9 «ai|sufler», 1 «human|-» — редкая страта обязана попасть в выборку.
        foreach (range(1, 9) as $i) {
            $this->outgoing($chat, 'черновик №'.$i, 300 + $i, '2026-10-07 12:0'.$i, 'sufler', 'ai');
        }
        $this->outgoing($chat, 'ручной ответ', 399, '2026-10-07 13:00', '-', 'human');

        Artisan::call('support:replies-review', ['--week' => '2026-10-05', '--sample' => 5, '--seed' => 42, '--sheet' => $this->sheet]);

        $lines = file($this->sheet, FILE_IGNORE_NEW_LINES);
        $kinds = array_map(static fn (string $l): string => explode("\t", $l)[2], array_slice($lines, 1));
        $this->assertContains('human|-', $kinds);
        $this->assertContains('ai|sufler', $kinds);
    }

    public function test_reduced_sample_when_eligible_below_requested(): void
    {
        $chat = $this->chat(41004);
        foreach (range(1, 3) as $i) {
            $this->outgoing($chat, 'ответ №'.$i, 400 + $i, '2026-10-07 12:0'.$i);
        }

        Artisan::call('support:replies-review', ['--week' => '2026-10-05', '--sample' => 30, '--sheet' => $this->sheet]);
        $out = Artisan::output();

        $this->assertStringContainsString('"sample":3', $out);
        $this->assertStringContainsString('"reduced":true', $out);
    }

    public function test_stdout_is_aggregate_only_and_sheet_sanitized(): void
    {
        $chat = $this->chat(41005);
        $this->incoming($chat, 'СЕКРЕТВОПРОС что с записью?', 501);
        $this->outgoing($chat, "СЕКРЕТОТВЕТ с записью\nи табом\tвнутри", 502, '2026-10-07 12:00');

        Artisan::call('support:replies-review', ['--week' => '2026-10-05', '--sample' => 1, '--sheet' => $this->sheet]);
        $out = Artisan::output();

        // Наружу — ни текстов, ни контекста.
        $this->assertStringNotContainsString('СЕКРЕТОТВЕТ', $out);
        $this->assertStringNotContainsString('СЕКРЕТВОПРОС', $out);
        $this->assertStringNotContainsString('"text"', $out);

        // Лист: 6 колонок, переносы/табы вычищены, контекст подставлен.
        $lines = file($this->sheet, FILE_IGNORE_NEW_LINES);
        $this->assertCount(2, $lines);
        $cols = explode("\t", $lines[1]);
        $this->assertCount(6, $cols);
        $this->assertSame('СЕКРЕТОТВЕТ с записью и табом внутри', $cols[4]);
        $this->assertStringContainsString('СЕКРЕТВОПРОС', $cols[5]);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $chat = $this->chat(41006);
        $this->outgoing($chat, 'ответ', 601, '2026-10-07 12:00');

        Artisan::call('support:replies-review', ['--week' => '2026-10-05', '--sample' => 1, '--sheet' => $this->sheet, '--dry-run' => true]);

        $this->assertFileDoesNotExist($this->sheet);
        $this->assertStringContainsString('"dry_run":true', Artisan::output());
    }

    public function test_zero_eligible_is_inconclusive_without_sheet(): void
    {
        $chat = $this->chat(41007);
        $this->incoming($chat, 'только входящее', 701, '2026-10-07 12:00');

        Artisan::call('support:replies-review', ['--week' => '2026-10-05', '--sample' => 5, '--sheet' => $this->sheet]);

        $this->assertFileDoesNotExist($this->sheet);
        $this->assertStringContainsString('inconclusive', Artisan::output());
    }

    public function test_undelivered_and_placeholder_messages_are_excluded(): void
    {
        $chat = $this->chat(41008);
        $this->outgoing($chat, 'нормальный ответ', 801, '2026-10-07 12:00');
        $this->outgoing($chat, 'ждет отправки', -2, '2026-10-07 12:01'); // placeholder ≤ −1
        $this->outgoing($chat, 'застрял', 802, '2026-10-07 12:02', 'admin', 'ai', ['pending_delivery' => true]);

        Artisan::call('support:replies-review', ['--week' => '2026-10-05', '--sample' => 30, '--sheet' => $this->sheet]);

        $this->assertStringContainsString('"eligible":1', Artisan::output());
        $this->assertSame([1], $this->sheetIds()); // единственный доставленный исходящий (модельный id 1)
    }

    public function test_import_computes_shares_wilson_and_coverage(): void
    {
        $sheet = $this->sheet;
        file_put_contents($sheet, "id\tsent_at\tkind\tverdict\ttext\tcontext\n"
            ."1\t2026-10-06 10:00\tai|sufler\tok\tтекст А\tвопрос\n"
            ."2\t2026-10-06 10:01\tai|sufler\tok\tтекст Б\tвопрос\n"
            ."3\t2026-10-06 10:02\tai|sufler\tok\tтекст В\tвопрос\n"
            ."4\t2026-10-06 10:03\tai|sufler\tminor\tтекст Г\tвопрос\n"
            ."5\t2026-10-06 10:04\tai|sufler\twrong\tтекст Д\tвопрос\n"
            ."6\t2026-10-06 10:05\thuman|-\tna\tтекст Е\tвопрос\n"
            ."7\t2026-10-06 10:06\tai|sufler\t\tтекст Ж\tвопрос\n");

        $exit = Artisan::call('support:replies-review', ['--import' => $sheet]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exit);
        $this->assertSame(7, $payload['n_rows']);
        $this->assertSame(6, $payload['n_labeled']);
        $this->assertSame(1, $payload['n_unlabeled']);
        $this->assertSame(0.8571, $payload['coverage']);
        $this->assertSame(5, $payload['overall']['n_decisive']);
        $this->assertSame(0.6, $payload['overall']['ok_share']);
        $this->assertSame(0.8, $payload['overall']['ok_plus_minor_share']);
        $this->assertSame(1, $payload['overall']['na']);
        $this->assertEqualsWithDelta(0.2307, $payload['overall']['wilson95'][0], 0.002);
        $this->assertEqualsWithDelta(0.8824, $payload['overall']['wilson95'][1], 0.002);
        // per-kind: ai|sufler (3 ok/1 minor/1 wrong), human|- (1 na)
        $this->assertSame(0.6, $payload['by_kind']['ai|sufler']['ok_share']);
        $this->assertSame(0, $payload['by_kind']['human|-']['n_decisive']);
        $this->assertNull($payload['by_kind']['human|-']['ok_share']);
        // Тексты не покинули окружение: их нет в выводе.
        $this->assertStringNotContainsString('текст А', Artisan::output());
    }

    public function test_import_refusals(): void
    {
        // (a) пустая колонка вердиктов
        file_put_contents($this->sheet, "id\tverdict\ttext\n1\t\tтекст\n");
        $this->assertSame(1, Artisan::call('support:replies-review', ['--import' => $this->sheet]));
        $this->assertStringContainsString('No labeled rows', Artisan::output());

        // (b) неизвестный вердикт
        file_put_contents($this->sheet, "id\tverdict\ttext\n1\tmaybe\tтекст\n");
        $this->assertSame(1, Artisan::call('support:replies-review', ['--import' => $this->sheet]));
        $this->assertStringContainsString('outside ok|minor|wrong|na', Artisan::output());

        // (c) дубликаты id
        file_put_contents($this->sheet, "id\tverdict\ttext\n1\tok\tа\n1\tok\tб\n");
        $this->assertSame(1, Artisan::call('support:replies-review', ['--import' => $this->sheet]));
        $this->assertStringContainsString('duplicate', Artisan::output());

        // (d) нет колонки verdict
        file_put_contents($this->sheet, "id\ttext\n1\tтекст\n");
        $this->assertSame(1, Artisan::call('support:replies-review', ['--import' => $this->sheet]));
        $this->assertStringContainsString('missing the verdict column', Artisan::output());
    }

    public function test_msk_week_boundaries(): void
    {
        // Понедельник 00:10 MSK — внутри недели; следующее воскресенье 23:50 — внутри;
        // следующий понедельник 00:10 — уже нет.
        $chat = $this->chat(41009);
        $this->outgoing($chat, 'ранний', 901, '2026-10-05 00:10');
        $this->outgoing($chat, 'поздний', 902, '2026-10-11 23:50');
        $this->outgoing($chat, 'следующий', 903, '2026-10-12 00:10');

        Artisan::call('support:replies-review', ['--week' => '2026-10-05', '--sample' => 30, '--sheet' => $this->sheet]);

        $this->assertStringContainsString('"eligible":2', Artisan::output());
        $this->assertEqualsCanonicalizing([1, 2], $this->sheetIds()); // только два MSK-в-окне исходящих
    }
}
