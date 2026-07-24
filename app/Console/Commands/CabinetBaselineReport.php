<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Baseline-отчёт телеметрии кабинета (H962, Phase 0 ремейка, спека §4).
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
    protected $signature = 'cabinet:baseline {--days=14 : Окно отчёта, дней назад от сегодня}';

    protected $description = 'Baseline ремейка кабинета: счётчики событий спеки §4 за N дней (H962)';

    /** События §4, пишущиеся в activity_events (сервер + клиент). */
    private const ACTIVITY_EVENTS = [
        ActivityEvent::CABINET_HOME_VIEW,
        ActivityEvent::CABINET_CONTINUE_CLICK,
        ActivityEvent::CABINET_HOMEWORK_REWORK_CLICK,
        ActivityEvent::LESSON_MARK_MASTERED,
        ActivityEvent::COURSE_TAB_VIEW,
        ActivityEvent::LIBRARY_SHELF_VIEW,
        ActivityEvent::LIBRARY_RAIL_JUMP,
        ActivityEvent::OFFER_IMPRESSION,
        ActivityEvent::OFFER_CLICK,
        ActivityEvent::ACCESS_RENEWAL_START,
        ActivityEvent::ACCESS_RENEWAL_COMPLETE,
    ];

    /** События §4 без нынешней поверхности в кабинете — чтобы отчёт был честным про охват. */
    private const NO_CURRENT_SURFACE = [
        'offer.purchase' => 'атрибуция покупки к офферу — после чекаут-attribution',
        'support.topic.pick' => 'у виджета поддержки нет выбора темы',
        'path.station.view' => 'лестница пути — только в гибриде (R29.6)',
        'path.station.lit.impression' => 'лестница пути — только в гибриде (R29.6)',
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

        return self::SUCCESS;
    }
}
