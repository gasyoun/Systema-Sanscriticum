<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\CatalogFamilyAudit;
use Illuminate\Console\Command;

/**
 * Вердикт по семьям каталога (H3773, остаток H3122).
 *
 * НИЧЕГО НЕ МЕНЯЕТ. Близнец `catalog:audit-shells`: тот ищет, что можно
 * удалить, этот — сколько строк `courses` описывают одну программу. Режим
 * `--markdown` печатает готовый `docs/AUDIT_CATALOG_DUPLICATE_SHELLS_<дата>.md`,
 * чтобы отчёт в репозитории был воспроизводим одной командой, а не собран
 * руками.
 */
class AuditCatalogFamilies extends Command
{
    protected $signature = 'catalog:audit-families
        {--json : Машиночитаемый вывод}
        {--markdown : Готовый текст отчёта для docs/}
        {--all : В markdown печатать и семьи из одного курса}';

    protected $description = 'Вердикт по каждой семье курсов: streams | duplicate | unique. Только отчёт, ничего не меняет.';

    public function handle(CatalogFamilyAudit $audit): int
    {
        $rows = $audit->report();

        if ($this->option('json')) {
            $this->line((string) json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if ($this->option('markdown')) {
            $this->line($this->markdown($rows, (bool) $this->option('all')));

            return self::SUCCESS;
        }

        $this->table(
            ['Семья', 'Вердикт', 'Курсы', 'Почему'],
            array_map(fn (array $row): array => [
                mb_substr($row['family'], 0, 34),
                $this->verdictLabel($row['verdict']),
                implode(', ', array_column($row['members'], 'id')),
                $row['reasons'] === [] ? '—' : mb_substr(implode('; ', $row['reasons']), 0, 70),
            ], $rows),
        );

        $this->newLine();
        $this->info($this->summaryLine($rows));

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $rows */
    private function summaryLine(array $rows): string
    {
        $count = fn (string $verdict): int => count(array_filter($rows, fn (array $r) => $r['verdict'] === $verdict));

        return sprintf(
            'Семей %d: duplicate %d, streams %d, unique %d. Курсов %d. Ничего не изменено.',
            count($rows),
            $count(CatalogFamilyAudit::VERDICT_DUPLICATE),
            $count(CatalogFamilyAudit::VERDICT_STREAMS),
            $count(CatalogFamilyAudit::VERDICT_UNIQUE),
            array_sum(array_map(fn (array $r) => count($r['members']), $rows)),
        );
    }

    private function verdictLabel(string $verdict): string
    {
        return match ($verdict) {
            CatalogFamilyAudit::VERDICT_DUPLICATE => '⛔ duplicate',
            CatalogFamilyAudit::VERDICT_STREAMS => '✅ streams',
            default => '· unique',
        };
    }

    /**
     * Текст отчёта. Семьи из одного курса по умолчанию сворачиваются в один
     * список идентификаторов: строка «одна строка, вердикт unique, причин нет»
     * на каждый такой курс — шум, из-за которого настоящие дубли теряются.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function markdown(array $rows, bool $all): string
    {
        $date = now()->format('d-m-Y');
        $out = [];

        $out[] = '# Аудит каталога: семьи курсов — потоки или дубли';
        $out[] = '';
        $out[] = "_Created: {$date} · Last updated: {$date}_";
        $out[] = '';
        $out[] = 'Отчёт команды `php artisan catalog:audit-families --markdown`. Только чтение: ни одной записи в `courses`/`tariffs` не сделано.';
        $out[] = '';
        $out[] = $this->summaryLine($rows);
        $out[] = '';
        foreach ($this->classBreakdown($rows) as $line) {
            $out[] = $line;
        }

        $out[] = '';
        $out[] = '## Вердикты';
        $out[] = '';
        $out[] = '| Семья | Вердикт | Курсы семьи | Доказательства | Что делать |';
        $out[] = '|---|---|---|---|---|';

        $singles = [];

        foreach ($rows as $row) {
            if (! $all && $row['verdict'] === CatalogFamilyAudit::VERDICT_UNIQUE) {
                $singles[] = $row['members'][0];

                continue;
            }

            $out[] = sprintf(
                '| `%s` | %s | %s | %s | %s |',
                $row['family'],
                $this->verdictLabel($row['verdict']),
                $this->membersCell($row['members']),
                $this->evidenceCell($row['members'], $row['reasons']),
                $row['follow_up'] ?? '—',
            );
        }

        if ($singles !== []) {
            $out[] = '';
            $out[] = '## Семьи из одного курса (вердикт `unique`)';
            $out[] = '';
            $out[] = 'Разбора не требуют; перечислены, чтобы охват отчёта был полным — каждый курс каталога попал ровно в одну строку.';
            $out[] = '';
            foreach ($singles as $member) {
                $out[] = sprintf('- %d — %s (`%s`)', $member['id'], $member['title'], $member['slug']);
            }
        }

        $out[] = '';
        $out[] = '## Как читается вердикт';
        $out[] = '';
        $out[] = '- **streams** — несколько строк `courses`, и каждая отличима как самостоятельный поток: у неё есть собственные данные (роль `live`/`recording`) и собственный ключ потока — номер из названия либо дата первого платежа. Законно; складывать потоки между собой в отчётности нельзя (семантика `App\Support\CourseCadence`).';
        $out[] = '- **duplicate** — хотя бы одна строка не отличима: либо у неё нет ни блоков, ни активных тарифов, ни оплат, либо две строки претендуют на один и тот же поток. Требует разбора человеком.';
        $out[] = '- **unique** — в семье одна строка.';
        $out[] = '';
        $out[] = 'Порог намеренно строгий в пользу `duplicate`: ложный `duplicate` стоит одного взгляда админа, ложный `streams` прячет дубль от витрины насовсем.';
        $out[] = '';
        $out[] = '_Dr. Mārcis Gasūns_';

        return implode("\n", $out);
    }

    /**
     * Раскладка `duplicate` по классам — она, а не общее число, говорит, ЧТО
     * именно делать: оболочку разбирает `catalog:audit-shells` (чистка базы),
     * а близнец-запись правится в карточках витрины, где удалять нечего.
     *
     * Считается из данных, а не приписывается прогону вручную: иначе при
     * следующем прогоне вывод разошёлся бы с цифрами над ним.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function classBreakdown(array $rows): array
    {
        $byClass = [];

        foreach ($rows as $row) {
            foreach ($row['classes'] ?? [] as $class) {
                $byClass[$class][] = $row['family'];
            }
        }

        if ($byClass === []) {
            return [];
        }

        $labels = [
            CatalogFamilyAudit::CLASS_EMPTY_SHELL => 'осевшая оболочка — член семьи без единой собственной строки данных. Чистка базы; разбирается `catalog:audit-shells`, который отдельно проверяет, не отнимет ли удаление у человека единственную запись на курс',
            CatalogFamilyAudit::CLASS_RECORDING_TWIN => '**живой поток и его же запись, проданные отдельными строками каталога под одним номером потока.** Удалять нечего — у записи свои блоки, тарифы и оплаты; витрина и SEO при этом показывают одну программу дважды. Правка карточек, а не базы',
            CatalogFamilyAudit::CLASS_STREAM_COLLISION => 'два потока неразличимы: ни номер в названии, ни дата первого платежа их не разводят, и признака «в записи» нет. Нужен человек — назвать поток в названии курса',
        ];

        $out = ['## Из-за чего сработал `duplicate`', ''];

        foreach ($labels as $class => $label) {
            if (! isset($byClass[$class])) {
                continue;
            }

            $families = array_values(array_unique($byClass[$class]));
            sort($families);

            $out[] = sprintf(
                '- **%d %s** — %s. Семьи: %s.',
                count($families),
                $this->pluralFamilies(count($families)),
                $label,
                implode(', ', array_map(fn (string $f) => "`{$f}`", $families)),
            );
        }

        return $out;
    }

    private function pluralFamilies(int $n): string
    {
        $mod100 = $n % 100;
        $mod10 = $n % 10;

        if ($mod100 >= 11 && $mod100 <= 14) {
            return 'семей';
        }

        return match ($mod10) {
            1 => 'семья',
            2, 3, 4 => 'семьи',
            default => 'семей',
        };
    }

    /** @param list<array<string, mixed>> $members */
    private function membersCell(array $members): string
    {
        $parts = [];

        foreach ($members as $member) {
            $parts[] = sprintf(
                '%d %s (`%s`, %s%s)',
                $member['id'],
                $member['title'],
                $member['slug'],
                $member['role'],
                $member['manual_family'] ? ', семья задана вручную' : '',
            );
        }

        return implode('<br>', $parts);
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @param  list<string>  $reasons
     */
    private function evidenceCell(array $members, array $reasons): string
    {
        $parts = [];

        foreach ($members as $member) {
            $parts[] = sprintf(
                '%d: %s · блоков %d · активных тарифов %d (%s) · оплат %d · записано %d · первый платёж %s · группы: %s',
                $member['id'],
                $member['url'],
                $member['blocks'],
                $member['active_tariffs'],
                $member['tariff_keys'] === [] ? '—' : implode(', ', $member['tariff_keys']),
                $member['paid_payments'],
                $member['enrolled'],
                $member['first_paid_at'] ?? '—',
                $member['groups'] === [] ? '—' : implode(', ', $member['groups']),
            );
        }

        if ($reasons !== []) {
            $parts[] = '**Почему duplicate:** '.implode('; ', $reasons);
        }

        return implode('<br>', $parts);
    }
}
