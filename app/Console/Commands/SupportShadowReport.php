<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SupportAiReplyEvent;
use App\Models\TelegramSupportMessage;
use App\Services\Support\SupportDmAutoReply;
use Illuminate\Console\Command;

/**
 * H3765 A4 (рулинг R9): что теневая неделя показала.
 *
 * Тень пишет «я бы отправил вот это» ({@see SupportDmAutoReply::EVENT_SHADOW_WOULD_SEND})
 * и молчит. Эта команда сводит каждую такую запись с тем, что куратор ОТВЕТИЛ
 * НА САМОМ ДЕЛЕ тому же студенту в следующие 48 часов, и раскладывает совпадение
 * по полосам скора и по категориям.
 *
 * Что здесь можно и чего нельзя. Совпадение считается механически — по доле
 * общих слов черновика и живого ответа. Это НЕ оценка правильности: высокая
 * доля значит «бот сказал бы примерно то же», низкая — «сказал бы другое», и
 * ни то ни другое не говорит, кто был прав. Отчёт существует, чтобы человек
 * увидел числа перед живым включением автоотправки (R9); решение остаётся за
 * человеком, команда никаких флагов не трогает.
 *
 * Отдельно считается «тишина»: случаи, где бот отправил бы ответ, а куратор не
 * ответил вовсе. Это и есть та работа, ради которой автоотправку затевали.
 */
class SupportShadowReport extends Command
{
    protected $signature = 'support:shadow-report
        {--days=7 : Окно теневой недели в днях}
        {--join-hours=48 : Сколько часов ждать ответа куратора для сверки}
        {--write= : Записать отчёт в файл (по умолчанию docs/RESULTS_SUPPORT_SHADOW_WEEK_<дата>.md)}
        {--no-write : Только показать в консоли}';

    protected $description = 'H3765 A4: сверка теневых «отправил бы» с реальными ответами кураторов; точность по полосам скора. Только чтение.';

    /** Доля общих слов, выше которой считаем, что куратор сказал примерно то же. */
    private const OVERLAP_MATCH = 0.35;

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $joinHours = max(1, (int) $this->option('join-hours'));
        $since = now()->subDays($days);

        $shadow = SupportAiReplyEvent::query()
            ->where('event_type', SupportDmAutoReply::EVENT_SHADOW_WOULD_SEND)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get();

        $lines = [
            '# RESULTS — теневая неделя расширенного автоответа (H3765 A4)',
            '',
            '_Created: '.now()->format('d-m-Y').' · Last updated: '.now()->format('d-m-Y').'_',
            '',
            sprintf(
                'Окно: **%d дн.** (с %s). Окно сверки с ответом куратора: **%d ч**. Теневых событий: **%d**.',
                $days,
                $since->format('d-m-Y'),
                $joinHours,
                $shadow->count(),
            ),
            '',
            'Порождено `php artisan support:shadow-report`. Читающая сводка: ни одного исходящего, ни одного флага.',
            '',
        ];

        if ($shadow->isEmpty()) {
            $lines[] = '## Пусто';
            $lines[] = '';
            $lines[] = 'За окно тень не записала ни одного «отправил бы». Возможные причины, в порядке вероятности:';
            $lines[] = '';
            $lines[] = '1. Флаг `SUPPORT_DM_AUTO_REPLY_SHADOW` выключен на проде.';
            $lines[] = '2. Ни одна подсказка не прошла порог `support.faq_rag.shadow_min_score`.';
            $lines[] = '3. Подсказок не было вовсе — сверьтесь с `support:auto-reply-weekly`.';
            $lines[] = '';
            $lines[] = '**Живое включение автоотправки на пустом отчёте недопустимо** (рулинг R9): решать не по чему.';
            $lines[] = '';
            $lines[] = '_Dr. Mārcis Gasūns_';
            $lines[] = '';

            return $this->emit(implode("\n", $lines));
        }

        $rows = [];
        foreach ($shadow as $event) {
            $rows[] = $this->row($event, $joinHours);
        }

        $lines = array_merge($lines, $this->bandSection($rows), $this->categorySection($rows), $this->silenceSection($rows));

        $lines[] = '## Что решает человек';
        $lines[] = '';
        $lines[] = 'Живое включение автоотправки — решение человека (рулинги R9/R10), и эта команда его не делает.';
        $lines[] = 'Смотреть надо на две колонки: «примерно то же» по полосам скора (совпал ли бот с куратором)';
        $lines[] = 'и «куратор молчал» (сколько работы автоотправка сняла бы). Порог включать имеет смысл начиная';
        $lines[] = 'с той полосы, где первая колонка высока, а не с той, где много событий.';
        $lines[] = '';
        $lines[] = '_Dr. Mārcis Gasūns_';
        $lines[] = '';

        return $this->emit(implode("\n", $lines));
    }

    /**
     * @return array{score: float, category: string, matched: bool, answered: bool}
     */
    private function row(SupportAiReplyEvent $event, int $joinHours): array
    {
        $meta = is_array($event->meta) ? $event->meta : [];
        $chatId = (int) ($meta['telegram_chat_id'] ?? 0);
        $draft = (string) ($meta['draft'] ?? '');

        $curatorReply = TelegramSupportMessage::query()
            ->where('telegram_chat_id', $chatId)
            ->where('direction', 'outgoing')
            ->where('sent_at', '>=', $event->created_at)
            ->where('sent_at', '<=', $event->created_at?->copy()->addHours($joinHours))
            ->orderBy('sent_at')
            ->value('text');

        return [
            'score' => (float) ($meta['score'] ?? 0.0),
            'category' => (string) ($meta['category'] ?? '—'),
            'answered' => $curatorReply !== null && trim((string) $curatorReply) !== '',
            'matched' => $curatorReply !== null
                && $this->overlap($draft, (string) $curatorReply) >= self::OVERLAP_MATCH,
        ];
    }

    /**
     * @param  list<array{score: float, category: string, matched: bool, answered: bool}>  $rows
     * @return list<string>
     */
    private function bandSection(array $rows): array
    {
        $bands = [];
        foreach ($rows as $row) {
            $band = $this->scoreBand($row['score']);
            $bands[$band] ??= ['n' => 0, 'answered' => 0, 'matched' => 0];
            $bands[$band]['n']++;
            $bands[$band]['answered'] += $row['answered'] ? 1 : 0;
            $bands[$band]['matched'] += $row['matched'] ? 1 : 0;
        }
        ksort($bands);

        $lines = ['## 1. По полосам BM25-скора', ''];
        $lines[] = '«Примерно то же» — доля от тех, где куратор вообще ответил: только их и можно сравнивать.';
        $lines[] = '';
        $lines[] = '| Полоса скора | «Отправил бы» | Куратор ответил | Примерно то же | Совпадение |';
        $lines[] = '|---|---:|---:|---:|---:|';
        foreach ($bands as $band => $row) {
            $lines[] = sprintf(
                '| %s | %d | %d | %d | %s |',
                $band,
                $row['n'],
                $row['answered'],
                $row['matched'],
                $row['answered'] > 0 ? round(100 * $row['matched'] / $row['answered'], 1).' %' : '—',
            );
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  list<array{score: float, category: string, matched: bool, answered: bool}>  $rows
     * @return list<string>
     */
    private function categorySection(array $rows): array
    {
        $byCategory = [];
        foreach ($rows as $row) {
            $byCategory[$row['category']] ??= ['n' => 0, 'answered' => 0, 'matched' => 0];
            $byCategory[$row['category']]['n']++;
            $byCategory[$row['category']]['answered'] += $row['answered'] ? 1 : 0;
            $byCategory[$row['category']]['matched'] += $row['matched'] ? 1 : 0;
        }
        ksort($byCategory);

        $lines = ['## 2. По категориям', ''];
        $lines[] = 'D (деньги) и E (доступы) в тени отсутствуют по построению — рулинг R3 их исключает.';
        $lines[] = '';
        $lines[] = '| Категория | «Отправил бы» | Куратор ответил | Примерно то же | Совпадение |';
        $lines[] = '|---|---:|---:|---:|---:|';
        foreach ($byCategory as $category => $row) {
            $lines[] = sprintf(
                '| %s | %d | %d | %d | %s |',
                $category,
                $row['n'],
                $row['answered'],
                $row['matched'],
                $row['answered'] > 0 ? round(100 * $row['matched'] / $row['answered'], 1).' %' : '—',
            );
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * @param  list<array{score: float, category: string, matched: bool, answered: bool}>  $rows
     * @return list<string>
     */
    private function silenceSection(array $rows): array
    {
        $total = count($rows);
        $silent = count(array_filter($rows, static fn (array $r): bool => ! $r['answered']));

        return [
            '## 3. Тишина — та самая работа, ради которой всё затевалось',
            '',
            sprintf(
                'В **%d** случаях из %d (%s) бот отправил бы ответ, а куратор не ответил вовсе в окне сверки.',
                $silent,
                $total,
                $total > 0 ? round(100 * $silent / $total, 1).' %' : '—',
            ),
            '',
            'Это верхняя оценка снимаемой нагрузки, а не гарантия: часть тишины — вопросы, на которые',
            'и не надо было отвечать, часть — ответ вне окна сверки или другим каналом.',
            '',
        ];
    }

    private function scoreBand(float $score): string
    {
        return match (true) {
            $score < 8 => 'a  <8',
            $score < 10 => 'b  8–10',
            $score < 12 => 'c  10–12',
            $score < 15 => 'd  12–15',
            default => 'e  15+',
        };
    }

    /**
     * Доля слов черновика, встретившихся в ответе куратора. Грубо и намеренно:
     * точную семантику здесь мерить нечем, а порядок величины виден и так.
     */
    private function overlap(string $draft, string $reply): float
    {
        $words = static function (string $text): array {
            $normalized = mb_strtolower($text);
            $stripped = preg_replace('~[^\p{L}\p{N}\s]~u', ' ', $normalized) ?? '';

            return array_values(array_unique(array_filter(
                preg_split('~\s+~u', trim($stripped)) ?: [],
                static fn (string $w): bool => mb_strlen($w) >= 4,
            )));
        };

        $draftWords = $words($draft);
        if ($draftWords === []) {
            return 0.0;
        }

        $replyWords = array_flip($words($reply));
        $common = count(array_filter($draftWords, static fn (string $w): bool => isset($replyWords[$w])));

        return $common / count($draftWords);
    }

    private function emit(string $report): int
    {
        $this->line($report);

        if ($this->option('no-write')) {
            return self::SUCCESS;
        }

        $path = (string) ($this->option('write')
            ?: base_path('docs/RESULTS_SUPPORT_SHADOW_WEEK_'.now()->format('d-m-Y').'.md'));

        @mkdir(dirname($path), 0o775, true);
        file_put_contents($path, $report);
        $this->info("Отчёт записан: {$path}");

        return self::SUCCESS;
    }
}
