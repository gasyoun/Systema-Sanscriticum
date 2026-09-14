<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\MarketingSetting;
use App\Models\SupportAiReplyEvent;
use App\Services\Support\SupportDailyDigest;
use App\Services\Support\SupportDmAutoReply;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * H4429 (рулинг MG 08-09-2026: «live после недели тени, без переспрашивания»):
 * авто-рубильник живого режима LLM-ветки саппорта. Раз в день (09:00 MSK,
 * после утренней сводки) считает, сколько дней ПОДРЯД ветка формулировала
 * ответы в тени; 7 продуктивных дней подряд — живой режим включается сам,
 * переспрашивать никого не нужно.
 *
 * Гейты (все обязательны):
 *  - SUPPORT_DM_LLM_DRAFTS (config) включён — иначе ветка мертва, включать
 *    нечего: отказ с причиной в лог;
 *  - живой режим ещё НЕ включён (env-флаг или штамп в marketing_settings);
 *  - >= SupportDailyDigest::STREAK_MAX_DAYS (7) календарных дней подряд
 *    (включая вчерашний) с >= 1 событием тени или живой отправки;
 *  - пустой день сбрасывает серию (штатная пауза traffic'а — не повод
 *    включать живой режим «вслепую»).
 *
 * Включение: штамп support_llm_live_enabled_at в marketing_settings (его
 * читает SupportDailyDigest::llmLive(), а с ним и конвейер H4404) + аудит-
 * событие dm_llm_live_enabled с окном серии и счётчиками. Идемпототентно:
 * повторный прогон после включения — no-op.
 */
class EnableSupportLlmLive extends Command
{
    protected $signature = 'support:llm-live-enable
        {--dry : Показать решение, ничего не включая}';

    protected $description = 'Включить живой режим LLM-ветки саппорта после 7 продуктивных дней тени (H4429).';

    public function handle(): int
    {
        if (! (bool) config('features.support_dm_llm_drafts', false)) {
            $this->info('support:llm-live-enable: SUPPORT_DM_LLM_DRAFTS выключен — живой режим не включается (ветка мертва).');
            Log::warning('H4429: авто-включение LLM-ветки отклонено — SUPPORT_DM_LLM_DRAFTS=false');

            return self::SUCCESS;
        }

        if (SupportDailyDigest::llmLive()) {
            $this->info('support:llm-live-enable: живой режим уже включён.');

            return self::SUCCESS;
        }

        $tz = (string) config('app.timezone', 'Europe/Moscow');
        $streak = $this->streakDays(CarbonImmutable::now($tz)->subDay()->startOfDay(), $tz);

        if ($streak < SupportDailyDigest::STREAK_MAX_DAYS) {
            $this->info(sprintf(
                'support:llm-live-enable: серия тени %d из %d дней — пока рано.',
                $streak,
                SupportDailyDigest::STREAK_MAX_DAYS,
            ));

            return self::SUCCESS;
        }

        if ($this->option('dry')) {
            $this->comment('--dry: серия '.$streak.' дней, живой режим был бы включён.');

            return self::SUCCESS;
        }

        $settings = MarketingSetting::query()->firstOrCreate([]);
        $settings->support_llm_live_enabled_at = now();
        $settings->save();

        SupportAiReplyEvent::create([
            'telegram_support_message_id' => null,
            'event_type' => 'dm_llm_live_enabled',
            'meta' => [
                'via' => SupportDmAutoReply::VIA,
                'streak_days' => $streak,
                'required_days' => SupportDailyDigest::STREAK_MAX_DAYS,
                'rule' => 'MG 08-09-2026: live после недели тени без переспрашивания',
            ],
        ]);

        Log::info('H4429: живой режим LLM-ветки включён авто-рубильником', ['streak_days' => $streak]);
        $this->info('Живой режим LLM-ветки ВКЛЮЧЁН (серия '.$streak.' дней тени).');

        return self::SUCCESS;
    }

    /**
     * Сколько последних календарных дней подряд (заканчивая $yesterday)
     * содержали >= 1 событие тени или живой отправки LLM-ветки.
     */
    private function streakDays(CarbonImmutable $yesterday, string $tz): int
    {
        $max = SupportDailyDigest::STREAK_MAX_DAYS;
        $from = $yesterday->subDays($max - 1)->startOfDay();

        $active = SupportAiReplyEvent::query()
            ->whereBetween('created_at', [$from, $yesterday->endOfDay()])
            ->whereIn('event_type', [
                SupportDmAutoReply::EVENT_LLM_SHADOW_WOULD_SEND,
                SupportDmAutoReply::EVENT_SENT,
            ])
            ->get(['created_at'])
            ->map(fn ($event) => $event->created_at->copy()->setTimezone($tz)->toDateString())
            ->unique()
            ->flip();

        $streak = 0;
        $cursor = $yesterday;
        while ($streak < $max && $active->has($cursor->toDateString())) {
            $streak++;
            $cursor = $cursor->subDay();
        }

        return $streak;
    }
}
