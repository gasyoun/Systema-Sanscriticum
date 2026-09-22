<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ContentCalendarSlot;
use App\Models\StoryPost;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Monday digest of the marketing autopilots (H5020, MG rulings Q10/Q19,
 * 16-09-2026): one Markdown doc per week listing every post the two
 * autopilots published (channel, slot type, link, time), every post the
 * §2.8 checklist held back, every price/date warning, and the current
 * flag state. The doc is what MG reads in the Monday window; the stop
 * rule (one factual error or one price/live-date slip ⇒ the matching flag
 * goes back to false the same pass) is applied by the human/agent reading
 * it, never by this command.
 *
 * Read-only over the tables; writes ONE file (storage/app/marketing/ or
 * --out). Runs Mondays 07:00 MSK (Kernel) and on demand.
 */
final class ContentAutopilotDigestCommand extends Command
{
    protected $signature = 'content:autopilot-digest
        {--since= : window start (Y-m-d [H:i]), default 7 days before --until}
        {--until= : window end (Y-m-d [H:i]), default now}
        {--out= : file path to write; default storage/app/marketing/autopilot-digest-<until>.md}
        {--stdout : print the digest instead of writing a file}';

    protected $description = 'Monday digest of posts published by the content-calendar and Telegram-stories autopilots';

    public function handle(): int
    {
        $until = $this->option('until') ? Carbon::parse((string) $this->option('until'), 'Europe/Moscow') : Carbon::now('Europe/Moscow');
        $since = $this->option('since') ? Carbon::parse((string) $this->option('since'), 'Europe/Moscow') : $until->copy()->subDays(7);

        $md = $this->render($since, $until);

        if ($this->option('stdout')) {
            $this->line($md);

            return self::SUCCESS;
        }

        $out = (string) ($this->option('out') ?: '');
        if ($out === '') {
            $rel = 'marketing/autopilot-digest-'.$until->format('Y-m-d').'.md';
            Storage::disk('local')->put($rel, $md);
            $out = Storage::disk('local')->path($rel);
        } else {
            $dir = dirname($out);
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            file_put_contents($out, $md);
        }

        $this->info("autopilot-digest: {$out}");

        return self::SUCCESS;
    }

    public function render(Carbon $since, Carbon $until): string
    {
        $calendarOn = (bool) config('features.content_calendar_autopilot');
        $storiesOn = (bool) config('features.telegram_story_publisher');
        $webhookSet = ! empty(config('services.n8n.calendar_post_webhook'));
        $channel = trim((string) config('services.telegram_story.channel_username', 'rusamskrtam'), '@');

        $slots = ContentCalendarSlot::query()
            ->where('status', ContentCalendarSlot::STATUS_PUBLISHED)
            ->whereBetween('updated_at', [$since, $until])
            ->orderBy('updated_at')
            ->get();

        $stories = StoryPost::query()
            ->where('status', StoryPost::STATUS_PUBLISHED)
            ->whereBetween('posted_at', [$since, $until])
            ->orderBy('posted_at')
            ->get();

        $heldSlots = ContentCalendarSlot::query()
            ->where('status', ContentCalendarSlot::STATUS_DRAFT)
            ->whereNotNull('meta->prohibition_hold')
            ->whereBetween('updated_at', [$since, $until])
            ->get();

        $heldStories = StoryPost::query()
            ->where('status', StoryPost::STATUS_DRAFT)
            ->where('journal', 'like', '%prohibition-hold%')
            ->whereBetween('updated_at', [$since, $until])
            ->get();

        $lines = [];
        $lines[] = '_Created: '.$until->format('d-m-Y').' · Last updated: '.$until->format('d-m-Y').'_';
        $lines[] = '';
        $lines[] = '# Автопилоты контента — понедельничный дайджест '.$since->format('d-m-Y').' → '.$until->format('d-m-Y');
        $lines[] = '';
        $lines[] = 'Окно пост-хок ревью H5020 (MG Q8/Q19, 16-09-2026 → 30-09-2026). Стоп-правило: ОДНА фактическая ошибка или ОДИН промах в цене / дате эфира в опубликованном посте → соответствующий флаг возвращается в `false` тем же проходом, отчет MG.';
        $lines[] = '';
        $lines[] = '## Состояние флагов на момент генерации';
        $lines[] = '';
        $lines[] = '| Флаг | Значение | Примечание |';
        $lines[] = '|---|---|---|';
        $lines[] = '| `CONTENT_CALENDAR_AUTOPILOT` | '.($calendarOn ? '✅ true' : '⛔ false').' | '.($webhookSet ? 'вебхук n8n задан' : '⚠️ `N8N_CALENDAR_POST_WEBHOOK` пуст — публикаций в VK нет, тик пропускается').' |';
        $lines[] = '| `TELEGRAM_STORY_PUBLISHER` | '.($storiesOn ? '✅ true' : '⛔ false').' | канал @'.$channel.' |';
        $lines[] = '';
        $lines[] = '## Опубликовано автопилотом — '.($slots->count() + $stories->count()).' пост(ов)';
        $lines[] = '';
        $lines[] = '| Канал | Тип слота | Ссылка | Время (МСК) | Предупреждение чек-листа |';
        $lines[] = '|---|---|---|---|---|';
        foreach ($slots as $slot) {
            $meta = $slot->meta ?? [];
            $link = (string) ($meta['link'] ?? '');
            $when = isset($meta['autopilot_published_at']) ? (string) $meta['autopilot_published_at'] : $slot->updated_at?->timezone('Europe/Moscow')->format('Y-m-d H:i');
            $warn = isset($meta['prohibition_warn']) ? implode('; ', (array) $meta['prohibition_warn']) : '—';
            $lines[] = '| VK ('.$slot->channel.') | '.$slot->slot_type.' #'.$slot->id.' | '.($link !== '' ? '['.$link.']('.$link.')' : '— (ссылку на пост дает n8n, в слоте нет)').' | '.$when.' | '.$warn.' |';
        }
        foreach ($stories as $post) {
            $link = 'https://t.me/'.$channel.($post->telegram_message_id ? '/'.$post->telegram_message_id : '');
            $warn = preg_match('/prohibition-warn: (.+)$/m', (string) $post->journal, $m) === 1 ? $m[1] : '—';
            $lines[] = '| Telegram @'.$channel.' | story '.$post->source.'/'.$post->source_key.' #'.$post->id.' | ['.$link.']('.$link.') | '.$post->posted_at?->timezone('Europe/Moscow')->format('Y-m-d H:i').' | '.$warn.' |';
        }
        if ($slots->isEmpty() && $stories->isEmpty()) {
            $lines[] = '| — | — | — | — | за окно автопилоты ничего не опубликовали |';
        }
        $lines[] = '';
        $lines[] = '## Удержано чек-листом §2.8 — '.($heldSlots->count() + $heldStories->count());
        $lines[] = '';
        if ($heldSlots->isEmpty() && $heldStories->isEmpty()) {
            $lines[] = 'Нет.';
        } else {
            $lines[] = '| Где | # | Причина |';
            $lines[] = '|---|---|---|';
            foreach ($heldSlots as $slot) {
                $lines[] = '| календарь VK | '.$slot->id.' | '.(string) (($slot->meta ?? [])['prohibition_hold'] ?? '').' |';
            }
            foreach ($heldStories as $post) {
                $reason = preg_match('/prohibition-hold §2\.8: (.+)$/m', (string) $post->journal, $m) === 1 ? $m[1] : '';
                $lines[] = '| story_posts | '.$post->id.' | '.$reason.' |';
            }
        }
        $lines[] = '';
        $lines[] = '## Вердикт MG (заполняется в понедельничном окне)';
        $lines[] = '';
        $lines[] = '- [ ] фактических ошибок нет · [ ] цен/дат не задето → флаги остаются `true`';
        $lines[] = '- [ ] ошибка найдена в посте № … → флаг `…` выключен тем же проходом (см. откат в docs/CONTENT_AUTOPILOT_TWO_WEEK_REVIEW_2026-09.md)';
        $lines[] = '';
        $lines[] = '_Гасунс_';

        return implode("\n", $lines)."\n";
    }
}
