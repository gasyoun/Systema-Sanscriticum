<?php

namespace App\Services\TelegramSupport;

use App\Models\TelegramSupportContact;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SupportContactUserAutoLinker
{
    /** @var array<string, User|null>|null */
    private ?array $usersByTelegramId = null;

    /** @var array<string, User|null>|null */
    private ?array $usersByTelegramUsername = null;

    /** @var array<string, User|null>|null */
    private ?array $usersByNormalizedName = null;

    /**
     * @return array{linked: int, by_telegram_id: int, by_username: int, by_name: int}
     */
    public function linkUnlinkedContacts(): array
    {
        $result = [
            'linked' => 0,
            'by_telegram_id' => 0,
            'by_username' => 0,
            'by_name' => 0,
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
     * @return array{user: User, source: 'by_telegram_id'|'by_username'|'by_name'}|null
     */
    private function match(TelegramSupportContact $contact): ?array
    {
        if ($user = $this->matchByTelegramId($contact)) {
            return ['user' => $user, 'source' => 'by_telegram_id'];
        }

        if ($user = $this->matchByUsername($contact)) {
            return ['user' => $user, 'source' => 'by_username'];
        }

        if ($user = $this->matchByName($contact)) {
            return ['user' => $user, 'source' => 'by_name'];
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
        if (! $contact->username || ! Schema::hasColumn('users', 'note')) {
            return null;
        }

        $username = $this->normalizeTelegramUsername($contact->username);
        if ($username === '') {
            return null;
        }

        return $this->usersByTelegramUsername()[$username] ?? null;
    }

    private function matchByName(TelegramSupportContact $contact): ?User
    {
        if (! $contact->name) {
            return null;
        }

        $name = $this->normalizeName($contact->name);
        if (substr_count($name, ' ') < 1) {
            return null;
        }

        return $this->usersByNormalizedName()[$name] ?? null;
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
        if (! Schema::hasColumn('users', 'note')) {
            return $this->usersByTelegramUsername;
        }

        User::query()
            ->whereNotNull('note')
            ->get()
            ->each(function (User $user): void {
                foreach ($this->telegramUsernamesFromNote($user->note) as $username) {
                    $this->usersByTelegramUsername[$username] = array_key_exists($username, $this->usersByTelegramUsername)
                        ? null
                        : $user;
                }
            });

        return $this->usersByTelegramUsername;
    }

    /**
     * @return array<string, User|null>
     */
    private function usersByNormalizedName(): array
    {
        if ($this->usersByNormalizedName !== null) {
            return $this->usersByNormalizedName;
        }

        $this->usersByNormalizedName = [];
        User::query()
            ->whereNotNull('name')
            ->get()
            ->each(function (User $user): void {
                $name = $this->normalizeName($user->name);
                if ($name === '' || substr_count($name, ' ') < 1) {
                    return;
                }

                $this->usersByNormalizedName[$name] = array_key_exists($name, $this->usersByNormalizedName)
                    ? null
                    : $user;
            });

        return $this->usersByNormalizedName;
    }

    /**
     * @return array<int, string>
     */
    private function telegramUsernamesFromNote(?string $note): array
    {
        if (! $note) {
            return [];
        }

        preg_match_all('/(?:Telegram|TG|Телеграм)\s*:\s*([^\r\n,;]+)/iu', $note, $matches);

        return collect($matches[1] ?? [])
            ->map(fn (string $value): string => $this->normalizeTelegramUsername($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeTelegramUsername(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('~^https?://t\.me/~', '', $value) ?? $value;
        $value = preg_replace('~^t\.me/~', '', $value) ?? $value;
        $value = ltrim($value, '@');
        $value = trim($value);

        return preg_replace('/[^a-z0-9_]/', '', $value) ?? '';
    }

    private function normalizeName(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }
}
