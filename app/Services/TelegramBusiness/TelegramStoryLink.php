<?php

declare(strict_types=1);

namespace App\Services\TelegramBusiness;

/** Resolve a curated campaign link, falling back to the public source post. */
final class TelegramStoryLink
{
    /** @param array<string, mixed> $post */
    public static function fromPost(array $post): ?string
    {
        $caption = (string) ($post['caption'] ?? '');
        if (preg_match('~https://samskrte\.ru/ga/([a-z0-9-]+)~i', $caption, $match) === 1) {
            $slug = strtolower($match[1]);
            if (self::isTrackedSlug($slug)) {
                return 'https://samskrte.ru/ga/'.$slug;
            }
        }

        $chat = is_array($post['chat'] ?? null) ? $post['chat'] : [];
        $username = trim((string) ($chat['username'] ?? ''));
        $messageId = (int) ($post['message_id'] ?? 0);
        if ($username === '' || $messageId < 1 || preg_match('/^[a-z0-9_]{5,32}$/i', $username) !== 1) {
            return null;
        }

        return 'https://t.me/'.$username.'/'.$messageId;
    }

    private static function isTrackedSlug(string $slug): bool
    {
        if (is_array(config("tracked_links.links.{$slug}"))) {
            return true;
        }
        if (preg_match('/^(?<campaign>[a-z0-9]+)-(?<account>[a-z0-9]+)-st-[a-z0-9-]+-\d{8}-\d{2}$/', $slug, $parts) !== 1) {
            return false;
        }

        return is_array(config("tracked_links.story_campaigns.{$parts['campaign']}"))
            && is_string(config("tracked_links.story_accounts.{$parts['account']}"));
    }

    /** Telegram Bot API StoryArea link rectangle, inside the lower safe zone. */
    public static function area(string $url): string
    {
        return (string) json_encode([[
            'position' => [
                'x_percentage' => 50.0,
                'y_percentage' => 78.0,
                'width_percentage' => 72.0,
                'height_percentage' => 10.0,
                'rotation_angle' => 0.0,
                'corner_radius_percentage' => 5.0,
            ],
            'type' => ['type' => 'link', 'url' => $url],
        ]], JSON_UNESCAPED_SLASHES);
    }
}
