<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TelegramSupportContact;
use App\Services\Telegram\MadelineClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * telegram-folder:audience — сухой прогон: читает папку, пишет CSV, не шлёт.
 */
class TelegramFolderAudienceCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/telegram-folder-audience'));
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function filter(array $extra = []): array
    {
        return $extra + [
            '_' => 'dialogFilter',
            'id' => 7,
            'title' => ['_' => 'textWithEntities', 'text' => 'Кашмир', 'entities' => []],
            'pinned_peers' => [['_' => 'inputPeerUser', 'user_id' => 1, 'access_hash' => 11]],
            'include_peers' => [
                ['_' => 'inputPeerUser', 'user_id' => 1, 'access_hash' => 11],
                ['_' => 'inputPeerUser', 'user_id' => 2, 'access_hash' => 22],
                ['_' => 'inputPeerUser', 'user_id' => 3, 'access_hash' => 33],
                ['_' => 'inputPeerUser', 'user_id' => 4, 'access_hash' => 44],
                ['_' => 'inputPeerChannel', 'channel_id' => 9, 'access_hash' => 99],
            ],
            'exclude_peers' => [['_' => 'inputPeerUser', 'user_id' => 4, 'access_hash' => 44]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $filters
     */
    private function fakeClient(array $filters): object
    {
        $client = new class($filters)
        {
            public object $messages;

            public object $users;

            /** @param list<array<string, mixed>> $filters */
            public function __construct(array $filters)
            {
                $this->messages = new class($filters)
                {
                    public int $sent = 0;

                    /** @param list<array<string, mixed>> $filters */
                    public function __construct(private array $filters) {}

                    /** @return array<string, mixed> */
                    public function getDialogFilters(): array
                    {
                        return ['_' => 'messages.dialogFilters', 'filters' => [
                            ['_' => 'dialogFilterDefault'],
                            ...$this->filters,
                        ]];
                    }

                    public function sendMessage(): void
                    {
                        $this->sent++;
                    }
                };

                $this->users = new class
                {
                    /** @var list<int> */
                    public array $requested = [];

                    /**
                     * @param  array{id: list<array<string, mixed>>}  $params
                     * @return list<array<string, mixed>>
                     */
                    public function getUsers(array $params): array
                    {
                        $profiles = [
                            1 => ['first_name' => 'Анна', 'username' => 'anna', 'contact' => true],
                            2 => ['first_name' => 'Борис'],
                            3 => ['first_name' => 'Бот', 'bot' => true],
                        ];

                        return array_map(function (array $input) use ($profiles): array {
                            $this->requested[] = $input['user_id'];

                            return ['_' => 'user', 'id' => $input['user_id']] + $profiles[$input['user_id']];
                        }, $params['id']);
                    }
                };
            }
        };

        $this->app->instance(MadelineClientFactory::class, new class($client) extends MadelineClientFactory
        {
            public function __construct(private object $fake) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function open(?string $clientClass = null, ?string $sessionPath = null): object
            {
                return $this->fake;
            }
        });

        return $client;
    }

    /** @return list<list<string>> */
    private function csvRows(): array
    {
        $files = File::files(storage_path('app/telegram-folder-audience'));
        $this->assertCount(1, $files);

        return array_map(
            static fn (string $line): array => str_getcsv($line, ';', '"', ''),
            file($files[0]->getPathname(), FILE_IGNORE_NEW_LINES),
        );
    }

    public function test_classifies_recipients_and_never_sends(): void
    {
        $client = $this->fakeClient([$this->filter()]);
        TelegramSupportContact::query()->create([
            'telegram_user_id' => 1,
            'name' => 'Анна',
            'first_inbound_at' => '2026-05-01 10:00:00',
        ]);

        $this->artisan('telegram-folder:audience', ['folder' => 'кашмир'])
            ->expectsOutputToContain('ничего не отправлено')
            ->doesntExpectOutputToContain('Анна')
            ->assertSuccessful();

        $rows = $this->csvRows();
        $this->assertSame(
            ['1;eligible', '2;cold', '3;bot'],
            array_map(static fn (array $r): string => $r[0].';'.$r[3], array_slice($rows, 1)),
        );
        $this->assertSame('2026-05-01', $rows[1][5]);
        $this->assertSame([1, 2, 3], $client->users->requested, 'pinned+include без дублей, exclude вычтен');
        $this->assertSame(0, $client->messages->sent);
    }

    public function test_warns_when_folder_includes_categories(): void
    {
        $this->fakeClient([$this->filter(['contacts' => true])]);

        $this->artisan('telegram-folder:audience', ['folder' => 'Кашмир'])
            ->expectsOutputToContain('НЕПОЛОН')
            ->assertSuccessful();
    }

    public function test_unknown_folder_lists_available_titles(): void
    {
        $this->fakeClient([$this->filter()]);

        $this->artisan('telegram-folder:audience', ['folder' => 'Нет такой'])
            ->expectsOutputToContain('«Кашмир»')
            ->assertFailed();
    }

    public function test_ambiguous_partial_title_is_refused(): void
    {
        $this->fakeClient([
            $this->filter(['title' => 'Кашмир 2']),
            $this->filter(['id' => 8, 'title' => 'Кашмир 3']),
        ]);

        $this->artisan('telegram-folder:audience', ['folder' => 'Кашмир'])
            ->expectsOutputToContain('нескольким папкам')
            ->assertFailed();
    }

    public function test_busy_session_is_reported_without_csv(): void
    {
        $this->fakeClient([$this->filter()]);
        $lock = Cache::lock('madeline-session', 60);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('telegram-folder:audience', ['folder' => 'Кашмир'])
                ->expectsOutputToContain('другая команда')
                ->assertFailed();
        } finally {
            $lock->release();
        }

        $this->assertDirectoryDoesNotExist(storage_path('app/telegram-folder-audience'));
    }
}
