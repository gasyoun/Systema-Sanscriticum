<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * H1067 — resolved landing/channel copy for the marathon funnel.
 * MARATHON_LANDING_COPY_VARIANT is either a forced single variant (a|b, a
 * kill-switch for rollback) or the sentinel `ab`, which runs a live 50/50
 * split via resolveArmForRequest() below. Ruled 31-07-2026 (MG): run both
 * arms concurrently rather than pick one, mirroring the H1966 visual-skin
 * policy — see also MarathonVisual (an independent axis, do not conflate).
 */
final class MarathonLandingCopy
{
    /** Session key for the sticky per-visitor arm assignment (ab mode only). */
    public const SESSION_KEY = 'marathon_landing_copy_arm';

    public static function variantKey(): string
    {
        $key = strtolower((string) config('marathon_landing_copy.variant', 'a'));

        return in_array($key, ['a', 'b'], true) ? $key : 'a';
    }

    /**
     * The key actually rendered for THIS visitor. When the config is forced
     * to `a`/`b`, every visitor gets that variant (single-window rollout,
     * still supported as a rollback lever). When it is `ab`, each visitor is
     * bucketed once via a session-sticky coin flip and keeps that arm for
     * the rest of the session — including across the register() redirect,
     * so the Lead persisted in register() matches the copy the visitor saw.
     */
    public static function resolveArmForRequest(Request $request): string
    {
        $configured = strtolower((string) config('marathon_landing_copy.variant', 'a'));
        if (in_array($configured, ['a', 'b'], true)) {
            return $configured;
        }

        $arm = $request->session()->get(self::SESSION_KEY);
        if (in_array($arm, ['a', 'b'], true)) {
            return $arm;
        }

        $arm = crc32($request->session()->getId()) % 2 === 0 ? 'a' : 'b';
        $request->session()->put(self::SESSION_KEY, $arm);

        return $arm;
    }

    /**
     * @return array{
     *   label: string,
     *   hero_title: string,
     *   hero_subtitle: string,
     *   cta: string,
     *   benefits_heading: string,
     *   benefits: list<array{title: string, body: string}>
     * }
     */
    public static function variant(?string $key = null): array
    {
        $key ??= self::variantKey();
        $variants = config('marathon_landing_copy.variants', []);
        if (! isset($variants[$key]) || ! is_array($variants[$key])) {
            throw new InvalidArgumentException("Unknown marathon landing copy variant [{$key}]. Use a|b.");
        }

        return $variants[$key];
    }

    /**
     * Full bag for the public show view.
     *
     * @return array<string, mixed>
     */
    public static function forView(?string $key = null): array
    {
        $variant = self::variant($key);

        return [
            'variant_key' => $key ?? self::variantKey(),
            'variant' => $variant,
            'days' => config('marathon_landing_copy.days', []),
            'tracks' => config('marathon_landing_copy.tracks', []),
            'faq' => config('marathon_landing_copy.faq', []),
            'testimonial' => config('marathon.testimonial'),
            'paid_track_price' => (int) config('marathon.paid_track_price'),
            'coupon_amount' => (int) config('marathon.coupon_amount'),
            'host_name' => (string) config('marathon.host_name'),
        ];
    }

    /**
     * Fields for LandingPage upsert (Filament row / SEO / magnet slug).
     *
     * @return array<string, mixed>
     */
    public static function forLandingPage(?string $key = null): array
    {
        $variant = self::variant($key);
        $benefits = $variant['benefits'];
        $faq = config('marathon_landing_copy.faq', []);

        $content = [
            [
                'type' => 'hero_block',
                'data' => [
                    'title' => $variant['hero_title'],
                    'subtitle' => $variant['hero_subtitle'],
                    'button_text' => $variant['cta'],
                ],
            ],
            [
                'type' => 'features_block',
                'data' => [
                    'title' => $variant['benefits_heading'],
                    'items' => array_map(
                        static fn (array $b): array => [
                            'title' => $b['title'],
                            'text' => $b['body'],
                        ],
                        $benefits
                    ),
                ],
            ],
            [
                'type' => 'faq_block',
                'data' => [
                    'title' => 'Частые вопросы',
                    'items' => array_map(
                        static fn (array $item): array => [
                            'question' => $item['q'],
                            'answer' => $item['a'],
                        ],
                        $faq
                    ),
                ],
            ],
        ];

        return [
            'title' => 'Консультация по онлайн-курсам ОРС',
            'slug' => (string) config('marathon.landing_slug'),
            'is_active' => true,
            'is_listed' => true,
            'subtitle' => $variant['hero_subtitle'],
            'hero_description' => $variant['hero_subtitle'],
            'description' => $variant['hero_subtitle'],
            'button_text' => $variant['cta'],
            'bullet_1' => $benefits[0]['title'] ?? null,
            'bullet_2' => $benefits[1]['title'] ?? null,
            'features_title' => $variant['benefits_heading'],
            'feature_1_title' => $benefits[0]['title'] ?? null,
            'feature_1_text' => $benefits[0]['body'] ?? null,
            'feature_2_title' => $benefits[1]['title'] ?? null,
            'feature_2_text' => $benefits[1]['body'] ?? null,
            'feature_3_title' => $benefits[2]['title'] ?? null,
            'feature_3_text' => $benefits[2]['body'] ?? null,
            'content' => $content,
            'telegram_url' => (string) config('marathon.telegram_channel_url'),
        ];
    }

    /**
     * @return array{when: string, requires_testimonial: bool, text: string}|null
     */
    public static function channelPost(int $number): ?array
    {
        $posts = config('marathon_landing_copy.channel_posts', []);

        return isset($posts[$number]) && is_array($posts[$number]) ? $posts[$number] : null;
    }

    public static function resolvePostText(int $number): string
    {
        $post = self::channelPost($number);
        if ($post === null) {
            throw new InvalidArgumentException("Unknown channel post number [{$number}]. Use 1–5.");
        }

        $text = (string) $post['text'];
        $link = (string) config('marathon_landing_copy.landing_url');
        $text = str_replace('{link}', $link, $text);

        if (! empty($post['requires_testimonial'])) {
            $testimonial = trim((string) config('marathon.testimonial', ''));
            if ($testimonial === '') {
                throw new InvalidArgumentException(
                    'Post 5 requires MARATHON_TESTIMONIAL — inventing a quote is forbidden (H1067).'
                );
            }
            $text = str_replace('{testimonial}', $testimonial, $text);
        }

        return $text;
    }
}
