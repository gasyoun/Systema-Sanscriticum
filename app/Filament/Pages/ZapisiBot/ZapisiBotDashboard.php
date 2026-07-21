<?php

declare(strict_types=1);

namespace App\Filament\Pages\ZapisiBot;

use App\Filament\Clusters\ZapisiBot;
use App\Models\Group;
use App\Support\RoleGate;
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

    /** @return array{count: int, fetched_at: ?string, members: array<int, array<string, mixed>>}|null */
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

        return is_array($decoded) ? $decoded : null;
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
