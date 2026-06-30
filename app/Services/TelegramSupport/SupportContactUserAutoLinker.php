<?php

namespace App\Services\TelegramSupport;

use App\Models\TelegramSupportContact;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SupportContactUserAutoLinker
{
    /** @var array<string, User|null>|null */
    private ?array $usersByTelegramId = null;

    /** @var array<string, User|null>|null */
    private ?array $usersByTelegramUsername = null;

    /**
     * @return array{linked: int, by_telegram_id: int, by_username: int}
     */
    public function linkUnlinkedContacts(): array
    {
        $result = [
            'linked' => 0,
            'by_telegram_id' => 0,
            'by_username' => 0,
        ];

        TelegramSupportContact::query()
            ->whereNull('linked_user_id')
            ->with('chat')
            ->orderBy('id')
            ->chunkById(100, function (Collection $contacts) use (&$result): void {
                foreach ($contacts as $contact) {
                    $match = $this->match($contact);
                    if (! $match) {
                        continue;
                    }

                    $contact->forceFill(['linked_user_id' => $match['user']->id])->save();
                    $result['linked']++;
                    $result[$match['source']]++;
                }
            });

        if ($result['linked'] > 0) {
            Log::info('Telegram support contacts auto-linked to users', $result);
        }

        return $result;
    }

    /**
     * @return array{user: User, source: 'by_telegram_id'|'by_username'}|null
     */
    private function match(TelegramSupportContact $contact): ?array
    {
        if ($user = $this->matchByTelegramId($contact)) {
            return ['user' => $user, 'source' => 'by_telegram_id'];
        }

        if ($user = $this->matchByUsername($contact)) {
            return ['user' => $user, 'source' => 'by_username'];
        }

        return null;
    }

    private function matchByTelegramId(TelegramSupportContact $contact): ?User
    {
        if (! $contact->telegram_user_id || ! Schema::hasColumn('users', 'telegram_id')) {
            return null;
        }

        return $this->usersByTelegramId()[(string) $contact->telegram_user_id] ?? null;
    }

    private function matchByUsername(TelegramSupportContact $contact): ?User
    {
        if (! $contact->username || ! Schema::hasColumn('users', 'telegram_username')) {
            return null;
        }

        $username = $this->normalizeTelegramUsername($contact->username);
        if ($username === '') {
            return null;
        }

        return $this->usersByTelegramUsername()[$username] ?? null;
    }

    /**
     * @return array<string, User|null>
     */
    private function usersByTelegramId(): array
    {
        if ($this->usersByTelegramId !== null) {
            return $this->usersByTelegramId;
        }

        $this->usersByTelegramId = [];
        User::query()
            ->whereNotNull('telegram_id')
            ->get()
            ->each(function (User $user): void {
                $key = (string) $user->telegram_id;
                $this->usersByTelegramId[$key] = array_key_exists($key, $this->usersByTelegramId)
                    ? null
                    : $user;
            });

        return $this->usersByTelegramId;
    }

    /**
     * @return array<string, User|null>
     */
    private function usersByTelegramUsername(): array
    {
        if ($this->usersByTelegramUsername !== null) {
            return $this->usersByTelegramUsername;
        }

        $this->usersByTelegramUsername = [];
        if (! Schema::hasColumn('users', 'telegram_username')) {
            return $this->usersByTelegramUsername;
        }

        User::query()
            ->whereNotNull('telegram_username')
            ->get()
            ->each(function (User $user): void {
                $username = $this->normalizeTelegramUsername((string) $user->telegram_username);
                if ($username === '') {
                    return;
                }

                $this->usersByTelegramUsername[$username] = array_key_exists($username, $this->usersByTelegramUsername)
                    ? null
                    : $user;
            });

        return $this->usersByTelegramUsername;
    }

    private function normalizeTelegramUsername(string $value): string
    {
        $value = User::normalizeTelegramUsername($value) ?? '';
        $value = mb_strtolower($value);

        return preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
    }
}
