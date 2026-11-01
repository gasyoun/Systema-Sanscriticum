<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramSupportContact;
use RuntimeException;

/**
 * Состав папки чатов Telegram как список получателей — только чтение.
 *
 * Класс ничего не отправляет: это основа сухого прогона рассылки. Папка хранит
 * явный список чатов (include/pinned минус exclude) и, возможно, флаги категорий
 * («все контакты», «все группы»). Членов категории сервер Telegram в ответе НЕ
 * перечисляет, поэтому при таких флагах список неполон — см. dynamic_flags.
 */
class TelegramFolderAudience
{
    private const USERS_BATCH = 100;

    /** Флаги, делающие состав папки шире или уже явного списка чатов. */
    private const DYNAMIC_FLAGS = [
        'contacts', 'non_contacts', 'groups', 'broadcasts', 'bots',
        'exclude_muted', 'exclude_read', 'exclude_archived',
    ];

    /**
     * @return array{
     *     folder: array{id: int, title: string},
     *     dynamic_flags: list<string>,
     *     skipped_group_peers: int,
     *     recipients: list<array{user_id: int, name: string, username: ?string, status: string, is_contact: bool, first_inbound_at: ?string}>
     * }
     *
     * @throws RuntimeException если папка не найдена или название неоднозначно
     */
    public function resolve(object $client, string $folderTitle): array
    {
        $filter = $this->findFilter($client, $folderTitle);

        $excluded = [];
        foreach ((array) ($filter['exclude_peers'] ?? []) as $peer) {
            $id = $this->userId($peer);
            if ($id !== null) {
                $excluded[$id] = true;
            }
        }

        $inputUsers = [];
        $groupPeers = 0;
        $peers = array_merge((array) ($filter['pinned_peers'] ?? []), (array) ($filter['include_peers'] ?? []));
        foreach ($peers as $peer) {
            $type = is_array($peer) ? (string) ($peer['_'] ?? '') : '';
            if ($type === 'inputPeerChat' || $type === 'inputPeerChannel') {
                $groupPeers++;

                continue;
            }

            $id = $this->userId($peer);
            if ($id === null || isset($excluded[$id])) {
                continue;
            }

            $inputUsers[$id] = [
                '_' => 'inputUser',
                'user_id' => $id,
                'access_hash' => $peer['access_hash'] ?? 0,
            ];
        }

        return [
            'folder' => ['id' => (int) $filter['id'], 'title' => $this->title($filter)],
            'dynamic_flags' => array_values(array_filter(
                self::DYNAMIC_FLAGS,
                static fn (string $flag): bool => ! empty($filter[$flag]),
            )),
            'skipped_group_peers' => $groupPeers,
            'recipients' => $this->describe($client, $inputUsers),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findFilter(object $client, string $folderTitle): array
    {
        $response = (array) $client->messages->getDialogFilters();
        $filters = array_values(array_filter(
            (array) ($response['filters'] ?? $response),
            fn ($filter): bool => is_array($filter) && isset($filter['id']) && $this->title($filter) !== '',
        ));

        $needle = $this->normalize($folderTitle);
        $exact = array_values(array_filter($filters, fn (array $f): bool => $this->normalize($this->title($f)) === $needle));
        $matches = $exact !== [] ? $exact : array_values(array_filter(
            $filters,
            fn (array $f): bool => $needle !== '' && str_contains($this->normalize($this->title($f)), $needle),
        ));

        if (count($matches) === 1) {
            return $matches[0];
        }

        $titles = implode(', ', array_map(fn (array $f): string => '«'.$this->title($f).'»', $filters));

        throw new RuntimeException(($matches === []
            ? "Папка «{$folderTitle}» не найдена."
            : "Название «{$folderTitle}» подходит нескольким папкам.")." Папки аккаунта: {$titles}.");
    }

    /**
     * @param  array<int, array<string, mixed>>  $inputUsers
     * @return list<array{user_id: int, name: string, username: ?string, status: string, is_contact: bool, first_inbound_at: ?string}>
     */
    private function describe(object $client, array $inputUsers): array
    {
        $inbound = TelegramSupportContact::query()
            ->whereIn('telegram_user_id', array_keys($inputUsers))
            ->whereNotNull('first_inbound_at')
            ->pluck('first_inbound_at', 'telegram_user_id');

        $recipients = [];
        foreach (array_chunk($inputUsers, self::USERS_BATCH) as $batch) {
            foreach ((array) $client->users->getUsers(['id' => $batch]) as $user) {
                if (! is_array($user) || ($user['_'] ?? '') !== 'user') {
                    continue;
                }

                $id = (int) $user['id'];
                $firstInbound = $inbound[$id] ?? null;

                $recipients[] = [
                    'user_id' => $id,
                    'name' => trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')),
                    'username' => $user['username'] ?? null,
                    'status' => $this->status($user, $firstInbound !== null),
                    'is_contact' => ! empty($user['contact']),
                    'first_inbound_at' => $firstInbound?->toDateString(),
                ];
            }
        }

        return $recipients;
    }

    /**
     * eligible — писал сам; cold — входящих нет в support-таблицах (импорт может
     * быть неполным, поэтому это «не подтверждён», а не «никогда не писал»);
     * bot/deleted/self — получателями быть не могут.
     *
     * @param  array<string, mixed>  $user
     */
    private function status(array $user, bool $hasInbound): string
    {
        return match (true) {
            ! empty($user['self']) => 'self',
            ! empty($user['deleted']) => 'deleted',
            ! empty($user['bot']) => 'bot',
            $hasInbound => 'eligible',
            default => 'cold',
        };
    }

    private function userId(mixed $peer): ?int
    {
        return is_array($peer) && ($peer['_'] ?? '') === 'inputPeerUser' && isset($peer['user_id'])
            ? (int) $peer['user_id']
            : null;
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    private function title(array $filter): string
    {
        $title = $filter['title'] ?? '';

        return trim((string) (is_array($title) ? ($title['text'] ?? '') : $title));
    }

    private function normalize(string $title): string
    {
        return mb_strtolower(trim($title));
    }
}
