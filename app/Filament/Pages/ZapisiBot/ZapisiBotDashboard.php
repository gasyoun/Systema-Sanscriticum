<?php

declare(strict_types=1);

namespace App\Filament\Pages\ZapisiBot;

use App\Filament\Clusters\ZapisiBot;
use App\Models\Group;
use App\Support\RoleGate;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Track C (H164): read-only dashboard tab for @zapisi_ORSbot — member roster
 * (D9 snapshot) + recent messages/media (D7+D8 corpus lane), both read
 * straight from the out-of-git store (never a DB table — PII/rights per the
 * Legal gate, this is a private booking chat, not a publishable group).
 *
 * Мультичат: выпадающий список с поиском по учебным группам с заданным
 * telegram_chat_id (реестр чатов = группы, тот же источник, что и у напоминаний),
 * ниже — состав+сообщения выбранной группы. Дип-линк ?group=<id>.
 */
class ZapisiBotDashboard extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $cluster = ZapisiBot::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Обзор';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = '@zapisi_ORSbot';

    protected static ?string $slug = 'dashboard';

    protected static string $view = 'filament.pages.zapisi-bot.dashboard';

    /** Состояние формы-селектора: ['group_id' => <id>]. */
    public array $data = [];

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    public function mount(): void
    {
        $groups = $this->groups;

        // Дип-линк ?group=<id>, иначе первая группа со списка.
        $requested = (int) request('group', 0);
        $default = $groups->contains('id', $requested)
            ? $requested
            : $groups->first()?->id;

        $this->form->fill(['group_id' => $default]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('group_id')
                    ->label('Чат группы')
                    ->options(fn (): Collection => $this->groups->pluck('name', 'id'))
                    ->searchable()
                    ->native(false)
                    ->live()
                    ->placeholder('Выберите группу'),
            ])
            ->statePath('data');
    }

    /** Учебные группы с заданным Telegram-чатом — реестр чатов бота. */
    public function getGroupsProperty(): Collection
    {
        return Group::query()
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '')
            ->orderBy('name')
            ->get(['id', 'name', 'telegram_chat_id']);
    }

    public function getSelectedGroupProperty(): ?Group
    {
        $id = $this->data['group_id'] ?? null;

        return $id ? $this->groups->firstWhere('id', (int) $id) : null;
    }

    /**
     * Снимок состава + его возраст. Возраст здесь не украшение: снимок пишет
     * часовой `telegram-harvest:roster-groups` через MTProto-сессию, и любой его
     * сбой (сессия занята/выключена/зависла) оставляет СТАРЫЙ файл нетронутым.
     * До 27.07.2026 страница рисовала такой файл как ни в чём не бывало — состав
     * пятидневной давности выглядел текущим, и расхождение с Telegram заметил
     * только человек, сравнив глазами.
     *
     * @return array{count: int, fetched_at: ?string, members: array<int, array<string, mixed>>, fetched_at_human: ?string, is_stale: bool, stale_after_hours: int}|null
     */
    public function getRosterProperty(): ?array
    {
        $chatId = $this->chatId();
        if ($chatId === null) {
            return null;
        }

        $file = $this->storePath().'/roster/'.$chatId.'.json';
        if (! File::exists($file)) {
            return null;
        }

        $decoded = json_decode(File::get($file), true);
        if (! is_array($decoded)) {
            return null;
        }

        $staleAfterHours = (int) config('services.telegram_harvest.roster_stale_after_hours', 6);
        $fetchedAt = $this->parseTimestamp($decoded['fetched_at'] ?? null);

        $decoded['stale_after_hours'] = $staleAfterHours;
        $decoded['fetched_at_human'] = $fetchedAt?->diffForHumans();
        // Снимок без разбираемой даты считаем протухшим: «не знаем, когда сняли»
        // ближе к протухшему, чем к свежему.
        $decoded['is_stale'] = $staleAfterHours > 0
            && ($fetchedAt === null || $fetchedAt->lt(now()->subHours($staleAfterHours)));

        return $decoded;
    }

    /**
     * Свежесть потока сообщений. Тот же вопрос, что и у ростера, но для другой
     * дорожки (Bot API webhook → очередь → стор): «Последние сообщения» показывают
     * последние 50 записей независимо от их возраста, поэтому замолчавший вебхук
     * выглядит на экране точно так же, как тихий чат. Именно так пропажа
     * сообщений с 22.07.2026 продержалась незамеченной пять дней.
     *
     * @return array{last_sent_at: ?string, last_sent_human: ?string, is_silent: bool, silence_after_hours: int}
     */
    public function getCorpusFreshnessProperty(): array
    {
        $silenceAfterHours = (int) config('services.telegram_harvest.corpus_silence_after_hours', 48);

        $latest = null;
        foreach ($this->recentMessages as $message) {
            $sentAt = $this->parseTimestamp($message['sent_at'] ?? null);
            if ($sentAt !== null && ($latest === null || $sentAt->gt($latest))) {
                $latest = $sentAt;
            }
        }

        return [
            'last_sent_at' => $latest?->toIso8601String(),
            'last_sent_human' => $latest?->diffForHumans(),
            // Пустой чат (сообщений нет вовсе) — не повод кричать: тишину меряем
            // только когда есть от чего отсчитывать.
            'is_silent' => $silenceAfterHours > 0
                && $latest !== null
                && $latest->lt(now()->subHours($silenceAfterHours)),
            'silence_after_hours' => $silenceAfterHours,
        ];
    }

    private function parseTimestamp(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return rescue(fn () => CarbonImmutable::parse($value), null, false);
    }

    /** Аватар чата (out-of-git) как base64 data-URI, либо null. */
    public function getAvatarProperty(): ?string
    {
        $chatId = $this->chatId();
        if ($chatId === null) {
            return null;
        }

        $file = $this->storePath().'/roster/avatars/'.$chatId.'.jpg';
        if (! File::exists($file)) {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode(File::get($file));
    }

    /** @return array<int, array<string, mixed>> most recent messages first, capped at 50 */
    public function getRecentMessagesProperty(): array
    {
        $chatId = $this->chatId();
        if ($chatId === null) {
            return [];
        }

        $dir = $this->storePath().'/corpus/'.$chatId;
        if (! File::isDirectory($dir)) {
            return [];
        }

        $files = collect(File::files($dir))
            ->sortByDesc(fn ($file) => $file->getFilename())
            ->take(7);

        $messages = [];
        foreach ($files as $file) {
            foreach (array_reverse(explode("\n", trim(File::get($file->getPathname())))) as $line) {
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $messages[] = $decoded;
                }
                if (count($messages) >= 50) {
                    break 2;
                }
            }
        }

        return $messages;
    }

    public function getChatIdProperty(): ?string
    {
        return $this->chatId();
    }

    private function chatId(): ?string
    {
        $chatId = $this->selectedGroup?->telegram_chat_id;

        return $chatId !== null && $chatId !== '' ? (string) $chatId : null;
    }

    private function storePath(): string
    {
        return (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));
    }
}
