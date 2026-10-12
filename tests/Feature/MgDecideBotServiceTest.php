<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Telegram\MgDecideBotService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Контрол-бот MG (H6452): /decide рендерит ≤4 карточек @DECIDE из
 * mg-decide/cards.json в clone vote-inbox, тап вердикта пишет
 * decisions/<sheet_id>/pack-NN.json по schema/decisions-pack.schema.json
 * и пушит. Вердикты только MG — айфейс гейтится mg_decide.allowed_tg_ids.
 * Сеть замокана Http::fake(); vote-inbox — временный git-clone с bare-remote.
 */
class MgDecideBotServiceTest extends TestCase
{
    private const MG_ID = 5487293147;

    private string $inbox;

    private string $remote;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().'/mg-decide-test-'.uniqid();
        $this->remote = $base.'-remote.git';
        $this->inbox = $base.'-inbox';

        mkdir($this->remote, 0777, true);
        Process::timeout(30)->run(['git', 'init', '--bare', '-b', 'master', $this->remote]);

        mkdir($this->inbox, 0777, true);
        $this->git(['init', '-b', 'master']);
        $this->git(['remote', 'add', 'origin', $this->remote]);

        mkdir($this->inbox.'/mg-decide', 0777, true);
        file_put_contents($this->inbox.'/mg-decide/cards.json', (string) json_encode([
            'sheet_id' => 'uprava-mg-decide-test',
            'generated' => '11-10-2026',
            'cards' => [
                ['id' => 'kosha-year2', 'title' => 'Year-2 papers go/no-go', 'question' => 'A3/A4 кандидаты конкорданса?', 'recommendation' => 'go', 'deadline' => '17-10-2026'],
                ['id' => 'indomemy-slim', 'title' => 'IndoMemy slim?', 'question' => 'резать или держать всё в HEAD?', 'recommendation' => 'A', 'deadline' => '17-10-2026'],
                ['id' => 'card-three', 'title' => 'Третья', 'question' => '?', 'recommendation' => 'go', 'deadline' => ''],
                ['id' => 'card-four', 'title' => 'Четвёртая', 'question' => '?', 'recommendation' => '', 'deadline' => ''],
                ['id' => 'card-five', 'title' => 'Пятая — не влезет в батч', 'question' => '?', 'recommendation' => '', 'deadline' => ''],
            ],
        ], JSON_UNESCAPED_UNICODE));
        $this->git(['add', 'mg-decide/cards.json']);
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '-m', 'cards']);
        $this->git(['push', '-q', 'origin', 'master']);

        config([
            'mg_decide.bot_token' => 'fake-mg-decide-token',
            'mg_decide.allowed_tg_ids' => [self::MG_ID],
            'mg_decide.inbox_path' => $this->inbox,
            'mg_decide.pack_branch' => 'master',
        ]);
    }

    protected function tearDown(): void
    {
        // РОВНО свои два каталога: dirname($this->inbox) = /tmp — попытка
        // снять /tmp целиком убила paratest-воркеров в CI (проба 11-10).
        Process::timeout(30)->run(['rm', '-rf', $this->inbox, $this->remote]);

        parent::tearDown();
    }

    public function test_decide_renders_at_most_four_cards_with_verdict_buttons(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $consumed = (new MgDecideBotService)->handleUpdate([
            'message' => ['chat' => ['id' => self::MG_ID], 'from' => ['id' => self::MG_ID], 'text' => '/decide'],
        ]);

        $this->assertTrue($consumed);

        $cardMessages = 0;
        Http::assertSent(function ($request) use (&$cardMessages): bool {
            if (! str_contains($request->url(), '/sendMessage')) {
                return false;
            }
            $markup = json_decode((string) $request['reply_markup'], true) ?? [];
            if (($markup['inline_keyboard'][0][0]['callback_data'] ?? '') === '') {
                return false; // welcome-текст без кнопок
            }
            $cardMessages++;

            // рекомендация первой — строка «Рекомендация:» до текста вопроса
            $text = (string) $request['text'];
            if (str_contains($text, 'Рекомендация:')) {
                $this->assertMatchesRegularExpression('/Рекомендация: <b>[^<]+<\/b>\n\n.+/s', $text);
            }
            $this->assertSame('mgd:approve:', mb_substr((string) $markup['inline_keyboard'][0][0]['callback_data'], 0, 12));
            $this->assertSame('mgd:reject:', mb_substr((string) $markup['inline_keyboard'][0][1]['callback_data'], 0, 11));
            $this->assertSame('mgd:defer:', mb_substr((string) $markup['inline_keyboard'][0][2]['callback_data'], 0, 10));

            return true;
        });

        $this->assertSame(4, $cardMessages, 'ровно ≤4 карточек за вызов');
    }

    public function test_verdict_callback_writes_schema_shaped_pack_and_pushes(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $written = (new MgDecideBotService)->writePack(
            ['id' => 'kosha-year2', 'title' => 'Year-2 papers go/no-go'],
            'approve'
        );

        $pack = $written['pack'];
        // additionalProperties:false из схемы — ровно шесть ключей верхнего уровня
        $this->assertEqualsCanonicalizing(
            ['sheet_id', 'pack', 'pack_size', 'generated', 'decided', 'items'],
            array_keys($pack)
        );
        $this->assertSame('uprava-mg-decide-test', $pack['sheet_id']);
        $this->assertSame(1, $pack['pack']);
        $this->assertSame(1, $pack['pack_size']);
        $this->assertMatchesRegularExpression('/^\d{2}-\d{2}-\d{4}$/', $pack['generated']);
        $this->assertSame(1, $pack['decided']);
        $this->assertSame([['id' => 'kosha-year2', 'decision' => 'approve']], $pack['items']);
        $this->assertTrue($written['pushed']);
        $this->assertFileExists($this->inbox.'/decisions/uprava-mg-decide-test/pack-01.json');

        // проверка против HEAD: локальный HEAD == origin/master после push
        $heads = Process::timeout(15)->run("git -C {$this->inbox} rev-parse HEAD && git -C {$this->inbox} rev-parse origin/master");
        $lines = array_filter(array_map('trim', explode("\n", $heads->output())));
        $this->assertCount(2, $lines);
        $this->assertSame($lines[0], $lines[1]);

        // вторая карточка получает pack-02, а не перезаписывает pack-01
        $second = (new MgDecideBotService)->writePack(['id' => 'indomemy-slim'], 'defer');
        $this->assertSame(2, $second['pack']['pack']);
    }

    public function test_verdict_from_stranger_is_silently_dropped(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $consumed = (new MgDecideBotService)->handleUpdate([
            'callback_query' => [
                'id' => 'cb-stranger',
                'data' => 'mgd:approve:kosha-year2',
                'from' => ['id' => 11111111],
                'message' => ['chat' => ['id' => 11111111], 'message_id' => 1],
            ],
        ]);

        $this->assertTrue($consumed);
        Http::assertNothingSent();
        $this->assertFileDoesNotExist($this->inbox.'/decisions/uprava-mg-decide-test/pack-01.json');
    }

    public function test_stale_card_id_is_refused_without_pack_write(): void
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        (new MgDecideBotService)->handleUpdate([
            'callback_query' => [
                'id' => 'cb-stale',
                'data' => 'mgd:approve:no-such-card',
                'from' => ['id' => self::MG_ID],
                'message' => ['chat' => ['id' => self::MG_ID], 'message_id' => 9],
            ],
        ]);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/answerCallbackQuery'));
        $this->assertFileDoesNotExist($this->inbox.'/decisions/uprava-mg-decide-test/pack-01.json');
    }

    public function test_decided_cards_leave_the_queue(): void
    {
        (new MgDecideBotService)->writePack(['id' => 'kosha-year2'], 'approve');

        $pending = (new MgDecideBotService)->pendingCards();
        $ids = array_map(fn (array $card): string => (string) $card['id'], $pending);

        $this->assertNotContains('kosha-year2', $ids);
        $this->assertCount(4, $ids);
    }

    /** Один git-вызов в тестовом inbox; падение — исключение с выводом. */
    private function git(array $args): void
    {
        $run = Process::timeout(30)->run(array_merge(['git', '-C', $this->inbox], $args));
        if (! $run->successful()) {
            $this->fail('git failed: '.$run->errorOutput().$run->output());
        }
    }
}
