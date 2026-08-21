<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Filament\Pages\TelegramSupportAnalytics;
use App\Models\TelegramSupportMessage;
use App\Services\TelegramSupport\SupportDashboardPacketBuilder;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Вчерашняя сводка поддержки для Telegram (H3242). Числа — тот же пакет, что
 * Filament-страница; 🍎/gasuns — маркер исходящих H3233. Без имён студентов.
 */
final class SupportDailyDigest
{
    public const ANALYTICS_PATH = '/admin/telegram-support/telegram-support-analytics';

    public function __construct(
        private readonly SupportDashboardPacketBuilder $packetBuilder,
    ) {}

    /**
     * @return array{
     *     date: string,
     *     metrics: array<string, mixed>,
     *     topics: list<array{category: string, total: int}>,
     *     attribution: array{apple: int, gasuns: int, ai: int, other: int},
     *     url: string,
     *     text: string
     * }
     */
    public function snapshot(?string $date = null): array
    {
        $tz = (string) config('app.timezone', 'Europe/Moscow');
        $day = $date
            ? CarbonImmutable::parse($date, $tz)->startOfDay()
            : CarbonImmutable::now($tz)->subDay()->startOfDay();

        $packet = $this->packetBuilder->build($day->toDateString());
        $metrics = $packet['summary']['today'];
        $topics = $packet['topics'];
        $attribution = $this->attributionForDate($day);
        $url = $this->analyticsUrl();

        return [
            'date' => $day->toDateString(),
            'metrics' => $metrics,
            'topics' => $topics,
            'attribution' => $attribution,
            'url' => $url,
            'text' => $this->formatHtml($day, $metrics, $topics, $attribution, $url),
        ];
    }

    /**
     * @return array{apple: int, gasuns: int, ai: int, other: int}
     */
    private function attributionForDate(CarbonImmutable $day): array
    {
        $counts = ['apple' => 0, 'gasuns' => 0, 'ai' => 0, 'other' => 0];

        $messages = TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->whereBetween('sent_at', [$day->startOfDay(), $day->endOfDay()])
            ->get(['responder_type', 'responder_marker', 'ai_state']);

        foreach ($messages as $message) {
            if ($message->responder_type === 'ai' || $message->ai_state === 'sent') {
                $counts['ai']++;

                continue;
            }

            $marker = (string) ($message->responder_marker ?: SupportOutgoingAttribution::GASUNS_MARKER);
            if ($marker === SupportOutgoingAttribution::APPLE_MARKER) {
                $counts['apple']++;
            } elseif ($marker === SupportOutgoingAttribution::GASUNS_MARKER) {
                $counts['gasuns']++;
            } else {
                $counts['other']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  list<array{category: string, total: int}>  $topics
     * @param  array{apple: int, gasuns: int, ai: int, other: int}  $attribution
     */
    private function formatHtml(
        CarbonImmutable $day,
        array $metrics,
        array $topics,
        array $attribution,
        string $url,
    ): string {
        $topicLine = 'нет';
        if ($topics !== []) {
            $topicLine = implode(', ', array_map(
                fn (array $row) => e((string) $row['category']).' '.(int) $row['total'],
                array_slice($topics, 0, 8),
            ));
        }

        $lines = [
            '<b>Сводка поддержки за '.$day->format('d.m.Y').'</b>',
            '',
            'Обращений: '.(int) ($metrics['conversations'] ?? 0),
            'Входящих: '.(int) ($metrics['incoming'] ?? 0),
            'Исходящих: '.(int) ($metrics['outgoing'] ?? 0),
            'Неотвеченных: '.(int) ($metrics['unanswered'] ?? 0),
            'Новых контактов: '.(int) ($metrics['new_contacts'] ?? 0),
            'ИИ отправил: '.(int) ($metrics['ai_sent'] ?? 0),
            'Горбаченко '.SupportOutgoingAttribution::APPLE_MARKER.': '.$attribution['apple'],
            'Гасунс: '.$attribution['gasuns'],
        ];

        if ($attribution['other'] > 0) {
            $lines[] = 'Прочие исходящие: '.$attribution['other'];
        }

        $lines[] = 'Темы: '.$topicLine;
        $lines[] = '';
        $lines[] = '<a href="'.e($url).'">Аналитика</a>';

        return implode("\n", $lines);
    }

    public function analyticsUrl(): string
    {
        try {
            return TelegramSupportAnalytics::getUrl();
        } catch (Throwable) {
            return rtrim((string) config('app.url'), '/').self::ANALYTICS_PATH;
        }
    }
}
