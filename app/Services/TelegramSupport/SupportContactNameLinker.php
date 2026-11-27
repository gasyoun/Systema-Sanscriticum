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
        $parsed = self::parseName((string) $contact->name);
        if ($parsed === null) {
            return null;
        }

        $contactWords = array_merge($parsed['core'], $parsed['tail']);

        $strict = [];
        $loose = [];

        foreach ($this->userIndex() as $row) {
            // Минимум два слова контакта должны лечь в ядро ФИО: иначе
            // «Москва» в записи контакта сошьёт его с любым москвичом.
            if (count(array_intersect($parsed['core'], $row['core'])) < 2) {
                continue;
            }

            $loose[] = $row['user'];

            if (array_diff($contactWords, $row['words']) === []) {
                $strict[] = $row['user'];
            }
        }

        // Сначала точное совпадение вместе с городом: если оно однозначно —
        // это лучший ответ. Если города в кабинете нет вовсе (ФИО без хвоста),
        // строгих кандидатов ноль, и тогда решает совпадение по ядру — но
        // снова только когда кандидат ровно один.
        $user = match (true) {
            count($strict) === 1 => $strict[0],
            $strict === [] && count($loose) === 1 => $loose[0],
            default => null,
        };

        if ($user === null) {
            return null; // нет кандидата или двусмысленно
        }

        $otherContact = TelegramSupportContact::query()
            ->where('linked_user_id', $user->id)
            ->whereNotNull('telegram_user_id')
            ->where('telegram_user_id', '<>', $contact->telegram_user_id)
            ->exists();

        return $otherContact ? null : $user;
    }

    /**
     * Разбор имени на ядро (имя, фамилия, отчество) и хвост (город и прочее).
     *
     * Хвост отделяется запятой, точкой с запятой, « из », « в », « на »,
     * « городе ». В Telegram человек часто записан как «Сергей Мамонтов из
     * СПБ» или «Надежда Смирнова Ступино» — город и без запятой. Поэтому слова
     * контакта ищутся И в ядре, И в хвосте пользователя, но засчитывается
     * совпадение, только если минимум два слова легли в ядро.
     *
     * Меньше двух слов в ядре (ник, одно имя) — null: такие не сшиваем.
     *
     * @return array{core: list<string>, tail: list<string>}|null
     */
    public static function parseName(string $value): ?array
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return null;
        }

        $parts = preg_split('/[,;]|\bиз\b|\bв\b|\bна\b|\bгороде\b/u', $value, 2);
        $core = self::tokenize((string) ($parts[0] ?? ''));
        $tail = self::tokenize((string) ($parts[1] ?? ''));

        if (count($core) < 2) {
            return null;
        }

        return ['core' => $core, 'tail' => $tail];
    }

    /** @return list<string> */
    private static function tokenize(string $value): array
    {
        $value = preg_replace('/[^\p{L}\s\-]+/u', ' ', $value) ?? '';
        $stop = ['из', 'в', 'на', 'г', 'гор', 'город', 'обл', 'область', 'р', 'респ', 'пос', 'с', 'д', 'п', 'ст', 'мкр'];

        $words = preg_split('/[\s\-]+/u', trim($value)) ?: [];
        $words = array_values(array_unique(array_filter(
            $words,
            static fn (string $w): bool => $w !== '' && ! in_array($w, $stop, true),
        )));

        sort($words);

        return $words;
    }

    /** @return list<array{user: User, core: list<string>, words: list<string>}> */
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
                $parsed = self::parseName((string) $user->name);
                if ($parsed === null) {
                    return;
                }

                $this->userIndex[] = [
                    'user' => $user,
                    'core' => $parsed['core'],
                    'words' => array_merge($parsed['core'], $parsed['tail']),
                ];
            });

        return $this->userIndex;
    }
}
