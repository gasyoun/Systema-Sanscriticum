<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelegramSupportMessage;
use App\Support\RepliesReviewStats;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * H67xx — слепое выборочное ревью качества ИСХОДЯЩИХ ответов поддержки.
 *
 * Дополняет контур вопросов (H5709/H5768/H5773), но смотрит на исходящие:
 * детерминированная seeded-стратифицированная выборка ответов @rusamskrtam
 * (kind = responder_type → role → маркер + raw_payload.via), лист ревью в защищённый
 * storage/app/support-replies, наружу — ТОЛЬКО агрегаты; импорт человеческих
 * меток (ok|minor|wrong|na) считает ok-share по решающим строкам, Wilson 95 %
 * CI и покрытие. Порога качества в v1 нет — это решение человека.
 *
 * Тексты переписки не покидают одобренное окружение: ни stdout, ни логи,
 * ни гит, ни тесты не несут текстов/имён.
 */
class RepliesReviewCommand extends Command
{
    protected $signature = 'support:replies-review
        {--week= : ISO-дата понедельника MSK-недели (дефолт — предыдущая завершённая)}
        {--from= : ISO-дата начала окна (вместе с --to, MSK, включительно)}
        {--to= : ISO-дата конца окна (эксклюзивно)}
        {--sample=30 : размер выборки}
        {--seed= : целочисленное зерно (дефолт — производное от окна)}
        {--sheet= : путь листа (дефолт storage/app/support-replies/review-<from>..<to>-<seed>.tsv)}
        {--dry-run : посчитать и напечатать агрегаты, ничего не писать}
        {--import= : путь заполненного листа — печатает только агрегаты}
        {--record : с --import записать агрегат под storage/app/support-replies/runs/}';

    protected $description = 'Слепое выборочное ревью качества исходящих ответов поддержки (наружу — только агрегаты).';

    private const VERDICTS = ['ok', 'minor', 'wrong', 'na'];

    public function handle(): int
    {
        $import = (string) ($this->option('import') ?? '');
        if ($import !== '') {
            return $this->importSheet($import);
        }

        return $this->writeSheet();
    }

    private function writeSheet(): int
    {
        [$from, $to] = $this->windowBounds();
        $window = $from.'..'.$to;
        $sample = max(1, (int) $this->option('sample'));
        $seed = $this->resolveSeed($from, $to);
        $dryRun = (bool) $this->option('dry-run');

        $eligible = TelegramSupportMessage::query()
            ->where('direction', 'outgoing')
            ->where('sent_at', '>=', $this->localStart($from))
            ->where('sent_at', '<', $this->localStart($to))
            ->where('telegram_message_id', '>', 0)
            ->whereNotNull('text')
            ->get(['id', 'telegram_support_chat_id', 'responder_type', 'role', 'responder_marker', 'raw_payload', 'text', 'sent_at'])
            ->filter(function (TelegramSupportMessage $m): bool {
                if (trim((string) $m->text) === '') {
                    return false;
                }

                // Недоставленные placeholder-исходящие не считаем ответами.
                return ($m->raw_payload['pending_delivery'] ?? false) !== true;
            })
            ->values();

        $total = $eligible->count();
        if ($total === 0) {
            $this->line(json_encode([
                'gate' => 'inconclusive',
                'window' => $window,
                'eligible' => 0,
                'required' => $sample,
                'note' => 'no eligible outbound replies in the window; observations are not manufactured',
            ], JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        // Страты: kind = (responder_type|role)|(raw_payload.via ?? '-');
        // недопознанное — 'unattributed'. Страты — сырой композит, без догадок.
        $strata = [];
        foreach ($eligible as $m) {
            $kind = $this->kindOf($m);
            $strata[$kind][] = $m;
        }
        ksort($strata);

        $orderKey = static fn (TelegramSupportMessage $m): string => sha1($seed.':'.$m->id);
        foreach ($strata as &$rows) {
            usort($rows, static fn ($a, $b): int => strcmp($orderKey($a), $orderKey($b)));
        }
        unset($rows);

        $n = min($sample, $total);
        $picked = [];
        $remaining = $n;
        foreach ($strata as $kind => $rows) {
            if ($remaining <= 0) {
                break;
            }
            $take = count($rows) >= 1 ? max(1, (int) floor(count($rows) / $total * $n)) : 0;
            $take = min($take, $remaining, count($rows));
            foreach (array_slice($rows, 0, $take) as $m) {
                $picked[$m->id] = [$m, $kind];
            }
            $remaining -= $take;
        }
        // Добор по убыванию размера страты (крупные первыми), детерминированно.
        if ($remaining > 0) {
            $bySize = $strata;
            uksort($bySize, static function (string $a, string $b) use ($strata): int {
                return count($strata[$b]) <=> count($strata[$a]) ?: strcmp($a, $b);
            });
            foreach ($bySize as $kind => $rows) {
                foreach ($rows as $m) {
                    if ($remaining <= 0) {
                        break 2;
                    }
                    if (! isset($picked[$m->id])) {
                        $picked[$m->id] = [$m, $kind];
                        $remaining--;
                    }
                }
            }
        }

        // Fingerprint: состав и ТЕКСТЫ (sha1), независимо от порядка.
        $lines = [];
        foreach ($picked as $id => [$m, $kind]) {
            $lines[] = $id."\t".$kind."\t".sha1((string) $m->text);
        }
        sort($lines);
        $fingerprint = hash('sha256', implode("\n", $lines));

        $aggregates = [
            'sheet' => null,
            'window' => $window,
            'seed' => $seed,
            'sample' => count($picked),
            'eligible' => $total,
            'strata' => count($strata),
            'reduced' => $n < $sample,
            'fingerprint' => $fingerprint,
            'dry_run' => $dryRun,
            'note' => 'fill the verdict column inside the approved environment only',
        ];

        if ($dryRun) {
            $this->line(json_encode($aggregates, JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $path = (string) ($this->option('sheet') ?? '')
            ?: storage_path('app/support-replies/review-'.$window.'-'.$seed.'.tsv');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $fh = fopen($path, 'w');
        fwrite($fh, "id\tsent_at\tkind\tverdict\ttext\tcontext\n");
        foreach ($picked as [$m, $kind]) {
            fwrite($fh, implode("\t", [
                $m->id,
                $m->sent_at?->timezone('Europe/Moscow')->format('Y-m-d H:i') ?? '',
                $kind,
                '', // verdict — заполняет ревьюер
                $this->sanitize((string) $m->text),
                $this->sanitize($this->contextOf($m)),
            ])."\n");
        }
        fclose($fh);

        $perStratum = [];
        foreach ($strata as $kind => $rows) {
            $perStratum[$kind] = [
                'eligible' => count($rows),
                'picked' => count(array_filter($picked, static fn (array $p): bool => $p[1] === $kind)),
            ];
        }
        file_put_contents($path.'.manifest.json', json_encode([
            'window' => $window,
            'seed' => $seed,
            'sample' => count($picked),
            'eligible' => $total,
            'reduced' => $n < $sample,
            'strata' => $perStratum,
            'picked_ids' => array_keys($picked),
            'fingerprint' => $fingerprint,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $aggregates['sheet'] = basename($path);
        $this->line(json_encode($aggregates, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * Импорт заполненного листа: только агрегаты наружу.
     */
    private function importSheet(string $path): int
    {
        if (! is_file($path)) {
            $this->error("Sheet not found: {$path}");

            return self::FAILURE;
        }

        $fh = fopen($path, 'r');
        $header = fgetcsv($fh, 0, "\t");
        $idx = array_flip($header ?: []);
        foreach (['id', 'verdict'] as $col) {
            if (! isset($idx[$col])) {
                fclose($fh);
                $this->error("Sheet is missing the {$col} column.");

                return self::FAILURE;
            }
        }

        $rows = [];
        while (($line = fgetcsv($fh, 0, "\t")) !== false) {
            if (! isset($line[$idx['id']]) || trim((string) $line[$idx['id']]) === '') {
                continue;
            }
            $rows[] = [
                'id' => (int) $line[$idx['id']],
                'kind' => isset($idx['kind']) ? trim((string) ($line[$idx['kind']] ?? '')) : 'unattributed',
                'verdict' => mb_strtolower(trim((string) ($line[$idx['verdict']] ?? ''))),
                'text' => isset($idx['text']) ? (string) ($line[$idx['text']] ?? '') : '',
            ];
        }
        fclose($fh);

        $ids = array_map(static fn (array $r): int => $r['id'], $rows);
        if (count($ids) !== count(array_unique($ids))) {
            $this->error('Sheet has duplicate row ids — a review with duplicate IDs cannot be aggregated.');

            return self::FAILURE;
        }

        $labeled = array_values(array_filter($rows, static fn (array $r): bool => $r['verdict'] !== ''));
        $unlabeled = count($rows) - count($labeled);
        if ($labeled === []) {
            $this->error('No labeled rows found (verdict column is empty).');

            return self::FAILURE;
        }

        $invalid = array_values(array_filter(
            $labeled,
            static fn (array $r): bool => ! in_array($r['verdict'], self::VERDICTS, true)
        ));
        if ($invalid !== []) {
            $this->error(sprintf(
                '%d rows carry verdicts outside %s.',
                count($invalid),
                implode('|', self::VERDICTS),
            ));

            return self::FAILURE;
        }

        $overall = RepliesReviewStats::summarize(array_column($labeled, 'verdict'));
        $byKind = [];
        foreach ($labeled as $r) {
            $byKind[$r['kind'] !== '' ? $r['kind'] : 'unattributed'][] = $r['verdict'];
        }
        ksort($byKind);
        $byKind = array_map(static fn (array $v): array => RepliesReviewStats::summarize($v), $byKind);

        // Fingerprint листа (если тексты на месте) + сверка с манифестом.
        $fingerprint = null;
        $manifestMatch = null;
        $manifestPath = $path.'.manifest.json';
        $withText = array_filter($labeled, static fn (array $r): bool => $r['text'] !== '');
        if (count($withText) === count($labeled)) {
            $lines = array_map(
                static fn (array $r): string => $r['id']."\t".$r['kind']."\t".sha1($r['text']),
                $labeled
            );
            sort($lines);
            $fingerprint = hash('sha256', implode("\n", $lines));
            if (is_file($manifestPath)) {
                $manifest = json_decode((string) file_get_contents($manifestPath), true);
                $manifestMatch = is_array($manifest) && ($manifest['fingerprint'] ?? null) === $fingerprint;
            }
        }

        $payload = [
            'sheet' => basename($path),
            'n_rows' => count($rows),
            'n_labeled' => count($labeled),
            'n_unlabeled' => $unlabeled,
            'coverage' => count($rows) > 0 ? round(count($labeled) / count($rows), 4) : null,
            'overall' => $overall,
            'by_kind' => $byKind,
            'fingerprint' => $fingerprint,
            'manifest_match' => $manifestMatch,
            'note' => 'no gate threshold in v1 — the quality bar for weekly cadence is a human call',
        ];

        if ($this->option('record')) {
            $runs = storage_path('app/support-replies/runs');
            if (! is_dir($runs)) {
                mkdir($runs, 0775, true);
            }
            $record = $runs.'/import-'.pathinfo($path, PATHINFO_FILENAME).'.json';
            file_put_contents($record, json_encode(
                $payload + ['recorded_at' => CarbonImmutable::now('Europe/Moscow')->format('c')],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            ));
            $payload['recorded'] = basename($record);
        }

        $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * Сырой композит страты: responder (responder_type → role → маркер) + via.
     * Живой прод-замер 14-10: 183 исходящих недели = 90 «gasuns» + 90 «🐢» +
     * 3 авто; у синканных из Telegram человеческих исходящих responder_type и
     * role = unknown, персональный след живет ТОЛЬКО в responder_marker —
     * поэтому маркер и есть честный фолбэк. Ничего не угадываем: нет ничего —
     * страта «unattributed».
     */
    private function kindOf(TelegramSupportMessage $m): string
    {
        $responder = trim((string) ($m->responder_type ?? ''));
        if ($responder === '' || $responder === 'unknown') {
            $responder = trim((string) ($m->role ?? ''));
        }
        if ($responder === '' || $responder === 'unknown') {
            $marker = trim((string) ($m->responder_marker ?? ''));
            $responder = $marker !== ''
                ? 'human|'.$this->sanitize($marker)
                : 'unattributed';
        }

        $via = $m->raw_payload['via'] ?? null;
        $via = is_string($via) && trim($via) !== '' ? trim($via) : '-';

        return $responder.'|'.$via;
    }

    private function contextOf(TelegramSupportMessage $m): string
    {
        $context = TelegramSupportMessage::query()
            ->where('telegram_support_chat_id', $m->telegram_support_chat_id)
            ->where('direction', 'incoming')
            ->where('sent_at', '<', $m->sent_at)
            ->orderByDesc('sent_at')
            ->value('text');

        return mb_substr((string) $context, 0, 500);
    }

    private function sanitize(string $text): string
    {
        return str_replace(["\t", "\n", "\r"], [' ', ' ', ' '], $text);
    }

    /**
     * @return array{0: string, 1: string} [from-date, to-date-exclusive]
     */
    private function windowBounds(): array
    {
        $fromRaw = (string) ($this->option('from') ?? '');
        $toRaw = (string) ($this->option('to') ?? '');
        if ($fromRaw !== '' && $toRaw !== '') {
            return [
                CarbonImmutable::parse($fromRaw, 'Europe/Moscow')->toDateString(),
                CarbonImmutable::parse($toRaw, 'Europe/Moscow')->toDateString(),
            ];
        }

        $week = (string) ($this->option('week') ?? '');
        if ($week !== '') {
            $monday = CarbonImmutable::parse($week, 'Europe/Moscow')->startOfWeek(CarbonImmutable::MONDAY);

            return [$monday->toDateString(), $monday->addDays(7)->toDateString()];
        }

        // Предыдущая завершённая MSK-неделя (понедельник-старт).
        $monday = CarbonImmutable::now('Europe/Moscow')
            ->startOfWeek(CarbonImmutable::MONDAY)
            ->subDays(7);

        return [$monday->toDateString(), $monday->addDays(7)->toDateString()];
    }

    private function resolveSeed(string $from, string $to): int
    {
        $seed = (string) ($this->option('seed') ?? '');
        if ($seed !== '' && ctype_digit($seed)) {
            return (int) $seed;
        }

        return (int) sprintf('%u', crc32('replies:'.$from.'..'.$to));
    }

    private function localStart(string $date): string
    {
        return CarbonImmutable::parse($date, 'Europe/Moscow')
            ->timezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }
}
