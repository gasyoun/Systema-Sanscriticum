<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\User;

/**
 * H6090 / ростер v2, гейт Q5 (pii-guard, фаза 0): маскинг личностей студента
 * ДО любого LLM-вызова в suggester-шве.
 *
 * Тот же контракт, что tools/school_pii_guard.py в Uprava (05-10-2026):
 * identities — живой сет users (name, email, phone, telegram_username, vk_id;
 * значения короче 4 символов и allow-лист исключены), матчинг — standalone-токен
 * (?<!\w)…(?!\w) с /u (кириллица внутри \w, поэтому «Подписчики» не ловится за
 * «Подписчик»), плюс структурные фигуры email/телефона; теги <PII:…:N> —
 * псевдонимы, сквозная нумерация на вызов; значения идентичностей не
 * логируются и не кэшируются между инстансами.
 *
 * H6144 (фикс 1 по вердикту H6140 на PR #3027): телефонные значения и фигуры
 * матчатся ЦИФРОВОЙ нормализацией — '+7 (916) 123-45-67', '8-916-123-45-67'
 * и '+79161234567' это одна и та же личность (префикс +7/8 взаимозаменяем,
 * разделители между цифрами необязательны); канонический ключ тега —
 * нормализованные цифры, поэтому любое написание получает один тег.
 *
 * Fail-closed: если identity-сет недоступен (БД упала), maskOrFail() бросает —
 * вызывающий ОБЯЗАН исключить текст из промпта (черновик собирается из одних
 * фактов), а не отправлять сырой текст. Чистоту нельзя доказать → вызова нет.
 */
class SupportStudentPiiMasker
{
    /** Хэндл владельца контура встречается в текстах легитимно и личностью не является. */
    private const ALLOW = ['gasyoun'];

    private const STRUCT_EMAIL = '/[\w.+-]+@[\w-]+\.[\w.]{2,}/u';

    /** H6144: разделители между цифрами не меняют фигуру телефона. */
    private const STRUCT_PHONE = '/(?<!\d)(?:\+7|8)(?:[ \x{00A0}\t.()+\-]*\d){10}(?!\d)/u';

    /** Класс разделителей между цифрами телефонной личности (H6144). */
    private const PHONE_SEP = '[ \x{00A0}\t.()+\-]';

    /** Значение состоит только из цифр и телефонных разделителей. */
    private const PHONE_LIKE = '/^[+\d \x{00A0}\t.()+\-]+$/u';

    /** @var array<string, string>|null значение => поле (кэш на инстанс) */
    private ?array $identities = null;

    /** @var array<int, array{string, string, string}>|[поле, паттерн, канонический ключ] longest-first */
    private ?array $patterns = null;

    /** @var array<string, int> ключ (поле|значение или фигура|значение) => номер тега */
    private array $tags = [];

    /**
     * @return array{0: string, 1: int} замаскированный текст и число подстановок
     *
     * @throws \Throwable когда identity-сет недоступен (fail-closed, см. docblock)
     */
    public function maskOrFail(string $text): array
    {
        $this->tags = [];
        $this->ensureIdentities();
        $substitutions = 0;

        foreach ($this->patterns as [$field, $pattern, $canonical]) {
            $text = preg_replace_callback($pattern, function (array $m) use ($field, $canonical, &$substitutions): string {
                $substitutions++;

                return $this->tag($field, $canonical);
            }, $text);
        }

        foreach ([['email-shaped', self::STRUCT_EMAIL], ['phone-shaped', self::STRUCT_PHONE]] as [$kind, $pattern]) {
            $text = preg_replace_callback($pattern, function (array $m) use ($kind, &$substitutions): string {
                if (in_array($m[0], self::ALLOW, true)) {
                    return $m[0];
                }
                $substitutions++;

                return $this->tag($kind, $m[0]);
            }, $text);
        }

        return [$text, $substitutions];
    }

    /** Осталась ли в тексте standalone-личность или структурная фигура (round-trip гейт). */
    public function containsIdentity(string $text): bool
    {
        $this->ensureIdentities();
        foreach ($this->patterns as [, $pattern]) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }
        if (preg_match(self::STRUCT_EMAIL, $text) === 1 && ! in_array('', self::ALLOW, true)) {
            return true;
        }
        if (preg_match(self::STRUCT_PHONE, $text) === 1) {
            return true;
        }

        return false;
    }

    /** Сброс кэша identities (для долгоживущих воркеров после массовых правок users). */
    public function refresh(): void
    {
        $this->identities = null;
        $this->patterns = null;
    }

    private function ensureIdentities(): void
    {
        if ($this->patterns !== null) {
            return;
        }

        // Пары, не карта: PHP интитит числовые строковые ключи ('100500' → 100500),
        // и значение-личность в ключе ломает preg_quote/string-типы.
        $seen = [];
        $pairs = [];
        foreach (User::query()->select(['name', 'email', 'phone', 'telegram_username', 'vk_id'])->get() as $user) {
            foreach (['name', 'email', 'phone', 'telegram_username', 'vk_id'] as $field) {
                $value = trim((string) ($user->{$field} ?? ''));
                if (mb_strlen($value) >= 4 && ! in_array($value, self::ALLOW, true) && ! isset($seen[$value])) {
                    $seen[$value] = true;
                    $pairs[] = [$value, $field];
                }
            }
        }

        usort($pairs, fn (array $a, array $b): int => mb_strlen($b[0]) <=> mb_strlen($a[0]));
        $this->identities = array_column($pairs, 1, 0);
        $this->patterns = array_map(
            fn (array $p): array => $this->compileIdentity($p[0], $p[1]),
            $pairs,
        );
    }

    /**
     * Значение → [поле, паттерн, канонический ключ тега]. Телефонно-подобные
     * значения (только цифры и разделители) компилируются цифро-гибко (H6144):
     * форматированные написания — та же личность, ключ тега — нормализованные
     * цифры с префиксом 7, поэтому '+79161234567', '+7 (916) 123-45-67' и
     * '8-916-123-45-67' получают ОДИН тег-псевдоним на вызов.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function compileIdentity(string $value, string $field): array
    {
        $digits = preg_replace('/\D+/u', '', $value) ?? '';

        if ($digits !== '' && strlen($digits) >= 4 && preg_match(self::PHONE_LIKE, $value) === 1) {
            if (strlen($digits) === 11 && in_array($digits[0], ['7', '8'], true)) {
                $canonical = '7'.substr($digits, 1);
                $body = '';
                foreach (str_split(substr($canonical, 1)) as $digit) {
                    $body .= self::PHONE_SEP.'*'.$digit;
                }

                return [$field, '/(?<!\d)(?:\+7|8)'.$body.'(?!\d)/u', $canonical];
            }

            $body = '';
            foreach (str_split($digits) as $i => $digit) {
                $body .= ($i > 0 ? self::PHONE_SEP.'*' : '').$digit;
            }

            return [$field, '/(?<!\d)'.$body.'(?!\d)/u', $digits];
        }

        return [$field, '/(?<!\w)'.preg_quote($value, '/').'(?!\w)/u', $value];
    }

    private function tag(string $kind, string $value): string
    {
        $key = $kind.'|'.$value;
        $this->tags[$key] ??= count($this->tags) + 1;

        return sprintf('<PII:%s:%d>', $kind, $this->tags[$key]);
    }
}
