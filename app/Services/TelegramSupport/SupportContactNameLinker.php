<?php

declare(strict_types=1);

namespace App\Services\TelegramSupport;

use App\Models\TelegramSupportContact;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Сшивка контактов поддержки с пользователями по ФИО — отдельно от
 * автоматической сшивки и только по явной команде человека.
 *
 * Почему отдельно: {@see SupportContactUserAutoLinker} сшивает по telegram_id и
 * username, и тест `test_auto_linker_does_not_match_by_full_name_without_telegram_identity`
 * запрещает ему угадывать по ФИО. Это осознанная политика: в синхронизации
 * нет человека, который увидит ошибку, а «Романов Павел» в базе может быть не
 * один. Поэтому ФИО живёт здесь и запускается только
 * `telegram-support:link-contacts`, где прогон по умолчанию сухой и сморится
 * глазами до `--apply`.
 *
 * Зачем это вообще нужно: студент писал в аккаунт школы, диалог есть, но в
 * кабинете он не привязан — telegram_id и username пусты, и без ФИО канал
 * «от имени аккаунта» его не видит (сообщение о долге отправить некому).
 *
 * Правило узкое: все слова контакта входят в ФИО пользователя, слов не меньше
 * двух, и такой пользователь РОВНО один. Пользователь, у которого уже есть
 * привязанный диалог с другим Telegram-аккаунтом, не перешивается.
 */
final class SupportContactNameLinker
{
    /** @var list<array{user: User, words: list<string>}>|null */
    private ?array $userIndex = null;

    /**
     * @return array{linked: int, applied: bool, samples: list<array{contact_id: int, contact_name: string, chat_id: ?int, user_id: int, user_name: string}>}
     */
    public function linkByName(bool $apply = true): array
    {
        $result = ['linked' => 0, 'applied' => $apply, 'samples' => []];

        TelegramSupportContact::query()
            ->whereNull('linked_user_id')
            ->orderBy('id')
            ->chunkById(100, function ($contacts) use (&$result, $apply): void {
                foreach ($contacts as $contact) {
                    $user = $this->match($contact);
                    if ($user === null) {
                        continue;
                    }

                    if ($apply) {
                        // saved() на модели протянет сшивку в приватный чат.
                        $contact->forceFill(['linked_user_id' => $user->id])->save();
                    }

                    if (count($result['samples']) < 25) {
                        $result['samples'][] = [
                            'contact_id' => (int) $contact->id,
                            'contact_name' => (string) $contact->name,
                            'chat_id' => $contact->telegram_support_chat_id !== null ? (int) $contact->telegram_support_chat_id : null,
                            'user_id' => (int) $user->id,
                            'user_name' => (string) $user->name,
                        ];
                    }

                    $result['linked']++;
                }
            });

        if ($apply && $result['linked'] > 0) {
            Log::info('Support contacts linked to users by full name', ['linked' => $result['linked']]);
        }

        return $result;
    }

    private function match(TelegramSupportContact $contact): ?User
    {
        $words = self::nameWords((string) $contact->name);
        if ($words === null) {
            return null;
        }

        $candidates = [];
        foreach ($this->userIndex() as $row) {
            if (array_diff($words, $row['words']) === []) {
                $candidates[] = $row['user'];
                if (count($candidates) > 1) {
                    return null; // двусмысленно — не сшиваем
                }
            }
        }

        $user = $candidates[0] ?? null;
        if ($user === null) {
            return null;
        }

        $otherContact = TelegramSupportContact::query()
            ->where('linked_user_id', $user->id)
            ->whereNotNull('telegram_user_id')
            ->where('telegram_user_id', '<>', $contact->telegram_user_id)
            ->exists();

        return $otherContact ? null : $user;
    }

    /**
     * Слова имени: нижний регистр, город после запятой отброшен, цифры и
     * пунктуация вырезаны. Меньше двух слов (ник, одно имя) — null.
     *
     * @return list<string>|null
     */
    public static function nameWords(string $value): ?array
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        $comma = mb_strpos($value, ',');
        if ($comma !== false) {
            $value = mb_substr($value, 0, $comma);
        }

        $value = preg_replace('/[^\p{L}\s\-]+/u', ' ', $value) ?? '';
        $words = preg_split('/[\s\-]+/u', trim($value)) ?: [];
        $words = array_values(array_unique(array_filter($words, static fn (string $w): bool => $w !== '')));

        if (count($words) < 2) {
            return null;
        }

        sort($words);

        return $words;
    }

    /** @return list<array{user: User, words: list<string>}> */
    private function userIndex(): array
    {
        if ($this->userIndex !== null) {
            return $this->userIndex;
        }

        $this->userIndex = [];
        User::query()
            ->whereNotNull('name')
            ->get(['id', 'name'])
            ->each(function (User $user): void {
                $words = self::nameWords((string) $user->name);
                if ($words === null) {
                    return;
                }

                $this->userIndex[] = ['user' => $user, 'words' => $words];
            });

        return $this->userIndex;
    }
}
