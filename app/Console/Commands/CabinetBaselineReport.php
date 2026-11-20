<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityEvent;
use App\Services\Activity\OfferPurchaseAttribution;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Baseline-отчет телеметрии кабинета (H962, Phase 0 ремейка, спека §4).
 *
 * Агрегирует ВСЕ источники под именами §4: activity_events (новые события H962)
 * + существующие таблицы lesson_views (lesson.view.heartbeat) и
 * schedule_join_clicks source=cabinet (cabinet.live.zoom.click) — их события
 * НЕ дублируются в activity_events, см. комментарий в ActivityEvent.
 *
 * Это минимальный readout «события текут»; цели/targets по KPI сознательно
 * не считает — baseline фиксирует только наблюдаемые значения (R20).
 */
class CabinetBaselineReport extends Command
{
    protected $signature = 'cabinet:baseline {--days=14 : Окно отчета, дней назад от сегодня}';

    protected $description = 'Baseline ремейка кабинета: счетчики событий спеки §4 за N дней (H962)';

    /** События §4, пишущиеся в activity_events (сервер + клиент). */
    private const ACTIVITY_EVENTS = [
        ActivityEvent::CABINET_HOME_VIEW,
        ActivityEvent::CABINET_CONTINUE_CLICK,
        ActivityEvent::CABINET_HOMEWORK_REWORK_CLICK,
        ActivityEvent::LESSON_MARK_MASTERED,
        ActivityEvent::COURSE_TAB_VIEW,
        ActivityEvent::LIBRARY_SHELF_VIEW,
        ActivityEvent::LIBRARY_RAIL_JUMP,
        ActivityEvent::PATH_STATION_VIEW,
        ActivityEvent::PATH_STATION_LIT_IMPRESSION,
        ActivityEvent::OFFER_IMPRESSION,
        ActivityEvent::OFFER_CLICK,
        ActivityEvent::OFFER_PURCHASE,
        ActivityEvent::ACCESS_RENEWAL_START,
        ActivityEvent::ACCESS_RENEWAL_COMPLETE,
    ];

    /** События §4 без нынешней поверхности в кабинете — чтобы отчет был честным про охват. */
    private const NO_CURRENT_SURFACE = [
        'support.topic.pick' => 'у виджета поддержки нет выбора темы',
    ];

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $rows = [];

        $counts = DB::table('activity_events')
            ->selectRaw('event_type, COUNT(*) AS n, COUNT(DISTINCT user_id) AS uniq')
            ->whereIn('event_type', self::ACTIVITY_EVENTS)
            ->where('created_at', '>=', $since)
            ->groupBy('event_type')
            ->get()
            ->keyBy('event_type');

        foreach (self::ACTIVITY_EVENTS as $event) {
            $row = $counts->get($event);
            $rows[] = [$event, 'activity_events', (int) ($row->n ?? 0), (int) ($row->uniq ?? 0)];
        }

        // Существующие таблицы — под их §4-именами.
        $heartbeats = DB::table('lesson_views')
            ->selectRaw('COUNT(*) AS n, COUNT(DISTINCT user_id) AS uniq')
            ->where('created_at', '>=', $since)
            ->first();
        $rows[] = ['lesson.view.heartbeat', 'lesson_views', (int) ($heartbeats->n ?? 0), (int) ($heartbeats->uniq ?? 0)];

        $zoom = DB::table('schedule_join_clicks')
            ->selectRaw('COALESCE(SUM(click_count), 0) AS n, COUNT(DISTINCT user_id) AS uniq')
            ->where('source', 'cabinet')
            ->where('last_clicked_at', '>=', $since)
            ->first();
        $rows[] = ['cabinet.live.zoom.click', 'schedule_join_clicks', (int) ($zoom->n ?? 0), (int) ($zoom->uniq ?? 0)];

        $this->info("Baseline кабинета за {$days} дн. (с {$since->toDateTimeString()}):");
        $this->table(['Событие §4', 'Источник', 'Всего', 'Уник. студентов'], $rows);

        $this->line('Без нынешней поверхности (события появятся вместе с гибридом):');
        foreach (self::NO_CURRENT_SURFACE as $event => $why) {
            $this->line("  - {$event} — {$why}");
        }

        $this->renderAttribution($since);

        return self::SUCCESS;
    }

    /**
     * H7968 — агрегат атрибуции «клик по офферу → подтверждённая оплата».
     * PII-минимум: только COUNT/DISTINCT и счётчики, без сумм и пользователей.
     */
    private function renderAttribution($since): void
    {
        $windowDays = OfferPurchaseAttribution::windowDays();
        $flag = (bool) config('features.cabinet_offer_attribution', false);

        $this->line('');
        $this->line(
            'Атрибуция офферов (H7968): флаг cabinet_offer_attribution '
            .($flag ? 'ON' : 'OFF — offer.purchase не пишется')
            .'. Правило связки: пользователь + oффер (course при наличии) + confirmed paid; '
            ."окно «клик → подтверждение оплаты» — {$windowDays} сут. от подтверждения."
        );

        $purchases = DB::table('activity_events')
            ->selectRaw('COUNT(*) AS n, COUNT(DISTINCT user_id) AS uniq')
            ->where('event_type', ActivityEvent::OFFER_PURCHASE)
            ->where('created_at', '>=', $since)
            ->first();
        $this->table(
            ['Атрибутированных покупок (offer.purchase)', 'Уник. студентов'],
            [[(int) ($purchases->n ?? 0), (int) ($purchases->uniq ?? 0)]]
        );

        $clicks = DB::table('activity_events')
            ->where('event_type', ActivityEvent::OFFER_CLICK)
            ->where('created_at', '>=', $since)
            ->pluck('id');

        $attributedClickIds = DB::table('activity_events')
            ->where('event_type', ActivityEvent::OFFER_PURCHASE)
            ->pluck('event_data')
            ->map(fn ($raw): array => is_string($raw) ? (array) json_decode($raw, true) : (array) $raw)
            ->filter(fn (array $data): bool => isset($data['click_id']))
            ->map(fn (array $data): int => (int) $data['click_id'])
            ->all();

        $unmatched = $clicks->reject(fn ($id): bool => in_array((int) $id, $attributedClickIds, true))->count();

        $this->line(
            "Клики офферов (offer.click) за окно отчёта: {$clicks->count()}, "
            ."из них без атрибуции: {$unmatched} (ноль — тоже результат)."
        );
        $this->line(
            'Это НАБЛЮДАЕМЫЕ атрибутированные конверсии, НЕ причинный revenue lift: '
            .'связка «пользователь+окно» причинность не измеряет, влияние оффера на выручку не измерялось.'
        );
    }
}
