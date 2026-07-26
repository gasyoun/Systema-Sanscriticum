<?php

declare(strict_types=1);

namespace App\Services\Support;

/**
 * Детект «технического» входящего из userbot: keywords OR @mention OR reply-to-bot.
 * Только классификация payload — без записи в БД (см. TechnicalIssueRouter).
 */
class TechnicalIssueDetector
{
    /**
     * @param  array<string, mixed>  $payload  нормализованное сообщение синка
     */
    public function matches(array $payload): bool
    {
        if (($payload['direction'] ?? '') !== 'incoming') {
            return false;
        }

        $text = trim((string) ($payload['text'] ?? ''));
        if ($text === '') {
            return false;
        }

        if (! empty($payload['mentioned_bot']) || ! empty($payload['mentioned'])) {
            return true;
        }

        if (! empty($payload['reply_to_bot'])) {
            return true;
        }

        return $this->matchesKeywords($text);
    }

    public function matchesKeywords(string $text): bool
    {
        $haystack = mb_strtolower($text);

        foreach ($this->keywords() as $keyword) {
            $needle = mb_strtolower($keyword);
            if ($needle !== '' && mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function keywords(): array
    {
        $keywords = config('support_tech.keywords', []);

        return is_array($keywords) ? array_values(array_filter(array_map(
            static fn ($k): string => trim((string) $k),
            $keywords,
        ))) : [];
    }
}
