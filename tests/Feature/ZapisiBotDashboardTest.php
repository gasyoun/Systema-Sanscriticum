<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ZapisiBot\ZapisiBotDashboard;
use App\Models\Group;
use App\Models\User;
use App\Support\Roles;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Мультичат-дашборд @zapisi_ORSbot: реестр чатов = учебные группы с
 * telegram_chat_id; выпадающий список с поиском (Filament Select) переключает
 * состав/сообщения выбранной группы.
 */
class ZapisiBotDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
    }

    public function test_registry_lists_only_groups_with_chat_id_and_defaults_to_first(): void
    {
        $this->admin();

        $a = Group::create(['name' => 'Группа A', 'telegram_chat_id' => '-100111']);
        $b = Group::create(['name' => 'Группа B', 'telegram_chat_id' => '-100222']);
        Group::create(['name' => 'Группа без чата']); // нет telegram_chat_id → не в реестре

        $component = Livewire::test(ZapisiBotDashboard::class);

        // mount выбирает первую группу по имени; в шапке состава — её название.
        $component->assertSet('data.group_id', $a->id)
            ->assertSee('Группа A');

        // Реестр = только группы с чатом.
        $ids = $component->instance()->groups->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids);
    }

    public function test_selecting_group_switches_panel(): void
    {
        $this->admin();

        Group::create(['name' => 'Группа A', 'telegram_chat_id' => '-100111']);
        $b = Group::create(['name' => 'Группа B', 'telegram_chat_id' => '-100222']);

        Livewire::test(ZapisiBotDashboard::class)
            ->set('data.group_id', $b->id)
            ->assertSee('Группа B');
    }

    public function test_deep_link_group_query_param_preselects(): void
    {
        $this->admin();

        Group::create(['name' => 'Первая', 'telegram_chat_id' => '-100111']);
        $target = Group::create(['name' => 'Вторая', 'telegram_chat_id' => '-100222']);

        Livewire::withQueryParams(['group' => $target->id])
            ->test(ZapisiBotDashboard::class)
            ->assertSet('data.group_id', $target->id);
    }

    public function test_empty_state_when_no_group_has_chat_id(): void
    {
        $this->admin();
        Group::create(['name' => 'Без чата']);

        Livewire::test(ZapisiBotDashboard::class)
            ->assertSet('data.group_id', null)
            ->assertSee('telegram_chat_id');
    }

    public function test_shows_selected_chat_id(): void
    {
        $this->admin();
        Group::create(['name' => 'Группа A', 'telegram_chat_id' => '-100111']);

        Livewire::test(ZapisiBotDashboard::class)
            ->assertSee('chat_id:')
            ->assertSee('-100111');
    }

    /**
     * Снимок состава пишет часовой telegram-harvest:roster-groups, и любой его
     * сбой оставляет старый файл нетронутым. До 27.07.2026 страница рисовала
     * такой файл как текущий — состав пятидневной давности выглядел свежим.
     */
    public function test_stale_roster_snapshot_is_marked(): void
    {
        $this->admin();
        $store = storage_path('framework/testing/zbd-'.uniqid());
        config([
            'services.telegram_harvest.store_path' => $store,
            'services.telegram_harvest.roster_stale_after_hours' => 6,
        ]);

        Group::create(['name' => 'Группа A', 'telegram_chat_id' => '-100111']);
        $this->writeRoster($store, '-100111', now()->subHours(30));

        try {
            Livewire::test(ZapisiBotDashboard::class)->assertSee('Снимок протух');
        } finally {
            File::deleteDirectory($store);
        }
    }

    public function test_fresh_roster_snapshot_is_not_marked(): void
    {
        $this->admin();
        $store = storage_path('framework/testing/zbd-'.uniqid());
        config([
            'services.telegram_harvest.store_path' => $store,
            'services.telegram_harvest.roster_stale_after_hours' => 6,
        ]);

        Group::create(['name' => 'Группа A', 'telegram_chat_id' => '-100111']);
        $this->writeRoster($store, '-100111', now()->subMinutes(20));

        try {
            Livewire::test(ZapisiBotDashboard::class)->assertDontSee('Снимок протух');
        } finally {
            File::deleteDirectory($store);
        }
    }

    /** Порог берётся из конфига, а не зашит в страницу/шаблон. */
    public function test_roster_staleness_threshold_comes_from_config(): void
    {
        $this->admin();
        $store = storage_path('framework/testing/zbd-'.uniqid());
        config([
            'services.telegram_harvest.store_path' => $store,
            'services.telegram_harvest.roster_stale_after_hours' => 72,
        ]);

        Group::create(['name' => 'Группа A', 'telegram_chat_id' => '-100111']);
        $this->writeRoster($store, '-100111', now()->subHours(30));

        try {
            Livewire::test(ZapisiBotDashboard::class)->assertDontSee('Снимок протух');
        } finally {
            File::deleteDirectory($store);
        }
    }

    /**
     * Дорожка сообщений (Bot API webhook) независима от MTProto-сессии, и её
     * молчание внешне неотличимо от тихого чата: список последних 50 сообщений
     * рисуется независимо от их возраста. Именно так пропажа сообщений с
     * 22.07.2026 продержалась незамеченной пять дней.
     */
    public function test_silent_corpus_is_marked(): void
    {
        $this->admin();
        $store = storage_path('framework/testing/zbd-'.uniqid());
        config([
            'services.telegram_harvest.store_path' => $store,
            'services.telegram_harvest.corpus_silence_after_hours' => 48,
        ]);

        Group::create(['name' => 'Группа A', 'telegram_chat_id' => '-100111']);
        $this->writeMessage($store, '-100111', now()->subDays(5));

        try {
            Livewire::test(ZapisiBotDashboard::class)->assertSee('Новых сообщений нет дольше');
        } finally {
            File::deleteDirectory($store);
        }
    }

    public function test_recent_corpus_is_not_marked_as_silent(): void
    {
        $this->admin();
        $store = storage_path('framework/testing/zbd-'.uniqid());
        config([
            'services.telegram_harvest.store_path' => $store,
            'services.telegram_harvest.corpus_silence_after_hours' => 48,
        ]);

        Group::create(['name' => 'Группа A', 'telegram_chat_id' => '-100111']);
        $this->writeMessage($store, '-100111', now()->subHour());

        try {
            Livewire::test(ZapisiBotDashboard::class)->assertDontSee('Новых сообщений нет дольше');
        } finally {
            File::deleteDirectory($store);
        }
    }

    private function writeRoster(string $store, string $chatId, CarbonInterface $fetchedAt): void
    {
        File::ensureDirectoryExists($store.'/roster');
        File::put($store.'/roster/'.$chatId.'.json', json_encode([
            'peer' => $chatId,
            'fetched_at' => $fetchedAt->toIso8601String(),
            'count' => 2,
            'members' => [
                ['id' => 1, 'username' => 'ivan', 'name' => 'Иван', 'is_bot' => false, 'role' => 'user'],
                ['id' => 2, 'username' => null, 'name' => 'Бот', 'is_bot' => true, 'role' => 'admin'],
            ],
        ]));
    }

    private function writeMessage(string $store, string $chatId, CarbonInterface $sentAt): void
    {
        $dir = $store.'/corpus/'.$chatId;
        File::ensureDirectoryExists($dir);
        File::put($dir.'/'.$sentAt->format('Y-m-d').'.jsonl', json_encode([
            'telegram_chat_id' => (int) $chatId,
            'telegram_message_id' => 1,
            'author_name' => 'Иван',
            'text' => 'Тестовое сообщение',
            'has_media' => false,
            'sent_at' => $sentAt->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE)."\n");
    }

    public function test_renders_avatar_data_uri_when_file_present(): void
    {
        $this->admin();
        $store = storage_path('framework/testing/zbd-'.uniqid());
        config(['services.telegram_harvest.store_path' => $store]);

        $g = Group::create(['name' => 'Группа A', 'telegram_chat_id' => '-100111']);
        File::ensureDirectoryExists($store.'/roster/avatars');
        File::put($store.'/roster/avatars/-100111.jpg', 'JPEG');

        try {
            $component = Livewire::test(ZapisiBotDashboard::class)->assertSet('data.group_id', $g->id);
            $this->assertSame('data:image/jpeg;base64,'.base64_encode('JPEG'), $component->instance()->avatar);
            $component->assertSee('<img', false);
        } finally {
            File::deleteDirectory($store);
        }
    }
}
