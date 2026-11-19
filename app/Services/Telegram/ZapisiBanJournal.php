<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use Illuminate\Support\Facades\File;

/**
 * Локальный журнал hard-ban'ов Почтальона по чату (у бота нет API «список
 * забаненных»): {store}/roster/{chat_id}.banned.json. Из него дашборд
 * «Записи (бот)» строит список «Заблокированы» с кнопкой разбана, поэтому
 * сюда пишет любой путь бана — ручной из дашборда и по кнопке предложения.
 */
final class ZapisiBanJournal
{
    /**
     * @return list<array{id: int, username: ?string, name: ?string, banned_at: ?string, by_user_id: ?int}>
     */
    public function all(string $chatId): array
    {
        $decoded = $this->read($chatId);

        return array_values(array_filter(
            $decoded['members'],
            fn ($m): bool => is_array($m) && (int) ($m['id'] ?? 0) > 0,
        ));
    }

    /**
     * @param  array{id: int, username: ?string, name: ?string, banned_at: ?string, by_user_id: ?int}  $entry
     */
    public function remember(string $chatId, array $entry): void
    {
        $file = $this->path($chatId);
        File::ensureDirectoryExists(dirname($file));

        $decoded = $this->read($chatId);
        $id = (int) $entry['id'];
        $decoded['members'] = array_values(array_filter(
            $decoded['members'],
            fn ($m): bool => ! is_array($m) || (int) ($m['id'] ?? 0) !== $id,
        ));
        $decoded['members'][] = $entry;
        $decoded['updated_at'] = now()->toIso8601String();

        File::put($file, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function forget(string $chatId, int $telegramUserId): void
    {
        $file = $this->path($chatId);
        if (! File::exists($file)) {
            return;
        }

        $decoded = $this->read($chatId);
        $decoded['members'] = array_values(array_filter(
            $decoded['members'],
            fn ($m): bool => ! is_array($m) || (int) ($m['id'] ?? 0) !== $telegramUserId,
        ));
        $decoded['updated_at'] = now()->toIso8601String();

        File::put($file, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    public function path(string $chatId): string
    {
        return $this->storePath().'/roster/'.$chatId.'.banned.json';
    }

    /** @return array{members: array<int, mixed>} */
    private function read(string $chatId): array
    {
        $file = $this->path($chatId);
        if (! File::exists($file)) {
            return ['members' => []];
        }

        $raw = json_decode(File::get($file), true);
        if (! is_array($raw) || ! isset($raw['members']) || ! is_array($raw['members'])) {
            return ['members' => []];
        }

        return $raw;
    }

    private function storePath(): string
    {
        return (string) config('services.telegram_harvest.store_path', storage_path('app/telegram-harvest/raw'));
    }
}
