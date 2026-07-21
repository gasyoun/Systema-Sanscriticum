<?php

declare(strict_types=1);

namespace App\Filament\Pages\ZapisiBot;

use App\Filament\Clusters\ZapisiBot;
use App\Models\Group;
use App\Support\RoleGate;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

/**
 * Track C (H164): read-only dashboard tab for @zapisi_ORSbot — member roster
 * (D9 snapshot) + recent messages/media (D7+D8 corpus lane), both read
 * straight from the out-of-git store (never a DB table — PII/rights per the
 * Legal gate, this is a private booking chat, not a publishable group).
 *
 * Мультичат: слева список учебных групп с заполненным telegram_chat_id, справа —
 * состав+сообщения выбранной группы (мастер/деталь, как Helpdesk). Реестр чатов =
 * учебные группы (Group.telegram_chat_id), тот же источник, что и у напоминаний.
 */
class ZapisiBotDashboard extends Page
{
    protected static ?string $cluster = ZapisiBot::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Обзор';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = '@zapisi_ORSbot';

    protected static ?string $slug = 'dashboard';

    protected static string $view = 'filament.pages.zapisi-bot.dashboard';

    /** id выбранной учебной группы (левая панель). */
    public ?int $selectedGroupId = null;

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    public function mount(): void
    {
        $groups = $this->groups;

        // Дип-линк ?group=<id>, иначе первая группа со списка (как Helpdesk::mount).
        $requested = (int) request('group', 0);
        $this->selectedGroupId = $groups->contains('id', $requested)
            ? $requested
            : $groups->first()?->id;
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

    public function selectGroup(int $groupId): void
    {
        $this->selectedGroupId = $groupId;
    }

    public function getSelectedGroupProperty(): ?Group
    {
        if ($this->selectedGroupId === null) {
            return null;
        }

        return $this->groups->firstWhere('id', $this->selectedGroupId);
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
