<?php

declare(strict_types=1);

namespace App\Services\Anons;

use Illuminate\Support\Arr;
use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * H5049 R1: декларативный манифест публикации — одна версионируемая
 * YAML/JSON-документация кампании. Валидатор fail-closed: двусмысленный
 * или неполный ввод ОТКЛОНЯЕТСЯ до публикации.
 *
 * Формат (JSON == YAML):
 * {
 *   "version": 1,
 *   "campaign": "m26",
 *   "creative": "sep-start",
 *   "slot": "2026-09-18T08:00",          // bucket идемпотентности
 *   "test_mode": true,                    // R14: приватный тест-контур
 *   "test_destination": {"platform": "telegram_story", "account": "marcis_test"},
 *   "frames": [                           // упорядоченная серия (R12)
 *     {"asset": "/abs/photo.jpg", "caption": "…", "alt_text": "…",
 *      "cta_text": "страница записи", "cta_url": "https://samskrte.ru/ga/…"}
 *   ],
 *   "destinations": [
 *     {"platform": "telegram_story", "account": "rusamskrtam"}
 *   ]
 * }
 */
final class PublicationManifest
{
    public const VERSION = 1;

    public const PLATFORMS = ['telegram_story', 'telegram_post', 'vk', 'senler'];

    /** H6915: кадр-видео (sendVideo) вместо фото-кадра с выжженной плашкой. */
    public const KIND_VIDEO = 'video';

    public const KIND_PHOTO = 'photo';

    /** Bot API multipart-лимит для видео (см. TelegramPostAdapter). */
    public const MAX_VIDEO_BYTES = 50 * 1024 * 1024;

    /** @param array<string, mixed> $data */
    private function __construct(public readonly array $data) {}

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("Manifest file missing/unreadable: {$path}");
        }

        $raw = (string) file_get_contents($path);
        $isJson = str_ends_with(strtolower($path), '.json');

        // H4880-revert контракт (15-09-2026): symfony/yaml — DEV-ONLY,
        // рантайм читает JSON. На проде манифест — .json; YAML остаётся
        // доступным в dev/тестах, где пакет стоит.
        if (! $isJson && ! class_exists(Yaml::class)) {
            throw new InvalidArgumentException(
                'YAML manifest given, but symfony/yaml is not installed in this environment '
                .'(dev-only per the H4880 revert contract; prod reads JSON twins). '
                ."Convert the manifest to .json: {$path}"
            );
        }

        $decoded = $isJson ? json_decode($raw, true) : Yaml::parse($raw);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Manifest is neither valid YAML nor JSON.');
        }

        return new self($decoded);
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /** Канонический хэш (иммунитет к порядку ключей): отсортированный JSON. */
    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->sorted($this->data)));
    }

    /**
     * Валидация fail-closed. Возвращает список ошибок; пустой список —
     * манифест принят. Двусмысленность = ошибка, не предупреждение.
     *
     * @return list<string>
     */
    public function validate(): array
    {
        $errors = [];
        $d = $this->data;

        if (($d['version'] ?? null) !== self::VERSION) {
            $errors[] = 'version must be '.self::VERSION.'.';
        }

        foreach (['campaign', 'creative', 'slot'] as $field) {
            $value = $d[$field] ?? null;
            if (! is_string($value) || ! preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/i', str_replace([' ', ':', 'T'], '-', $value))) {
                $errors[] = "{$field} is required (short slug, no spaces).";
            }
        }

        $frames = $d['frames'] ?? null;
        if (! is_array($frames) || $frames === []) {
            $errors[] = 'frames[] is required: at least one ordered frame.';
        } else {
            foreach (array_values($frames) as $i => $frame) {
                if (! is_array($frame)) {
                    $errors[] = "frames[{$i}] must be an object.";

                    continue;
                }

                // H6915: kind=video → media_path вместо asset, CTA-плашка
                // не выжигается (видео неизменяемо), подпись несёт ссылку.
                $kind = $frame['kind'] ?? self::KIND_PHOTO;
                if (! in_array($kind, [self::KIND_PHOTO, self::KIND_VIDEO], true)) {
                    $errors[] = "frames[{$i}].kind must be 'photo' or 'video' (got: ".json_encode($kind).').';

                    continue;
                }

                $mediaField = $kind === self::KIND_VIDEO ? 'media_path' : 'asset';
                $media = $frame[$mediaField] ?? null;
                if (! is_string($media) || $media === '') {
                    $errors[] = "frames[{$i}].{$mediaField} is required (kind={$kind}).";
                } elseif (! str_starts_with($media, '/')) {
                    $errors[] = "frames[{$i}].{$mediaField} must be an ABSOLUTE path (ambiguity guard).";
                } elseif (! is_file($media) || ! is_readable($media)) {
                    $errors[] = "frames[{$i}].{$mediaField} is missing/unreadable: {$media}.";
                } elseif ($kind === self::KIND_VIDEO && (int) filesize($media) > self::MAX_VIDEO_BYTES) {
                    $errors[] = sprintf(
                        "frames[{$i}].media_path exceeds the Bot API 50MB video limit (%.1f MB): %s — re-encode or trim the fragment.",
                        filesize($media) / 1024 / 1024,
                        $media
                    );
                }

                $caption = $frame['caption'] ?? null;
                if ($caption !== null && ! is_string($caption)) {
                    $errors[] = "frames[{$i}].caption must be a string.";
                }

                // URL не бывает первым текстовым элементом (anons-правило
                // публикаций: человек видит CTA, не ссылку).
                if (is_string($caption) && preg_match('/^https?:\/\//i', $caption) === 1) {
                    $errors[] = "frames[{$i}]: caption must not START with a URL — the URL goes after the copy (anons publication rule).";
                }

                $alt = $frame['alt_text'] ?? null;
                if (! is_string($alt) || trim($alt) === '') {
                    $errors[] = "frames[{$i}].alt_text is required (accessibility + archive).";
                }

                $url = $frame['cta_url'] ?? null;
                if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                    $errors[] = "frames[{$i}].cta_url must be a valid absolute URL (short /ga/ redirect).";
                } elseif (preg_match('/utm_/i', $url) === 1) {
                    $errors[] = "frames[{$i}].cta_url must be the clean /ga/ short link — UTM lives server-side (presentation-safety, anons skill).";
                }

                if ($kind === self::KIND_PHOTO
                    && (! is_string($frame['cta_text'] ?? null) || trim((string) $frame['cta_text']) === '')) {
                    $errors[] = "frames[{$i}].cta_text is required — the visible plaque is burned into the image.";
                }
            }
        }

        $destinations = $d['destinations'] ?? null;
        if (! is_array($destinations) || $destinations === []) {
            $errors[] = 'destinations[] is required: at least one account/platform.';
        } else {
            $seen = [];
            foreach (array_values($destinations) as $i => $dest) {
                if (! is_array($dest)) {
                    $errors[] = "destinations[{$i}] must be an object.";

                    continue;
                }
                $platform = $dest['platform'] ?? null;
                $account = $dest['account'] ?? null;
                if (! in_array($platform, self::PLATFORMS, true)) {
                    $errors[] = "destinations[{$i}].platform must be one of: ".implode(', ', self::PLATFORMS).'.';
                }
                if (! is_string($account) || $account === '') {
                    $errors[] = "destinations[{$i}].account is required.";
                } else {
                    $key = $platform.'@'.$account;
                    if (isset($seen[$key])) {
                        $errors[] = "destinations: duplicate destination {$key} — one manifest entry per account (ambiguity guard).";
                    }
                    $seen[$key] = true;
                }
            }
        }

        // R14: test mode is fail-closed.
        if ($this->isTestMode()) {
            $testDest = $d['test_destination'] ?? null;
            if (! is_array($testDest)
                || ! in_array($testDest['platform'] ?? null, self::PLATFORMS, true)
                || ! is_string($testDest['account'] ?? null)
                || $testDest['account'] === '') {
                $errors[] = 'test_mode=true requires test_destination {platform, account} (private test contour).';
            }
        }

        $delete = $d['deletion_policy'] ?? 'retain';
        if (! in_array($delete, ['retain', 'rollback_target'], true)) {
            $errors[] = "deletion_policy must be 'retain' or 'rollback_target' (got: ".json_encode($delete).').';
        }

        return $errors;
    }

    public function isTestMode(): bool
    {
        return (bool) Arr::get($this->data, 'test_mode', false);
    }

    /** R14: прод-адресаты недостижимы из тест-режима. */
    public function effectiveDestinations(): array
    {
        if (! $this->isTestMode()) {
            return array_values($this->data['destinations'] ?? []);
        }

        $test = $this->data['test_destination'];

        return [[
            'platform' => $test['platform'],
            'account' => $test['account'],
            'test' => true,
        ]];
    }

    public function frames(): array
    {
        return array_values($this->data['frames'] ?? []);
    }

    /** @return array<string, mixed> */
    private function sorted(mixed $value): mixed
    {
        if (is_array($value)) {
            $assoc = array_is_list($value) === false;
            $out = array_map(fn ($v) => $this->sorted($v), $value);
            if ($assoc) {
                ksort($out);
            }

            return $out;
        }

        return $value;
    }
}
