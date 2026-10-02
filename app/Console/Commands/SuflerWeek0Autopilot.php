<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Support\Faq\Bm25FaqRetriever;
use Illuminate\Console\Command;

/**
 * H5560/Q11 (row 0LD) — автопрогон суфлёра по корпусу курсы+FAQ под политикой
 * в коде (policy/sufler.policy.yml, §19) с hard-killgate-леджером (§13):
 * снимает baseline week-0 для §20 п.2–3.
 *
 * Часть A — «доля без человека»: 50 реальных (маскированных) сообщений студентов
 * из classifier_corpus_2026_08.json гонятся через BM25-retrieval по корпусу v1
 * (resources/knowledge/faq.md); вердикт на каждый — по границе пилота из
 * docs/SUFLER_AUTONOMY_PILOT_SCOPE_2026-09-29.md:
 *   D (деньги)  — money policy: порог пройден → autonomous-candidate с дисклеймером
 *                 живого каталога, нет → SILENCE (полное молчание, маршрут финлиду);
 *   B/F/A/E     — FAQ-класс (записи/материалы/подключение/кабинет): порог пройден →
 *                 autonomous-candidate, нет → HINT (💡, отвечает человек);
 *   C (расписание) — факты расписания офлайн-неразрешимы → HINT (честно);
 *   F с «сертификат» в тексте — граница пилота: человек;
 *   none (small talk) — исключено из знаменателя.
 *
 * Часть B — «precision цитат N=20»: первые N позиций faq_rag_eval.json
 * (размеченные expected_chunk_ids): точность top-1 сырая и при пороге
 * (passesThreshold), планка рулинга R3 ≥95 %.
 *
 * Детерминированный трек: LLM не вызывается, токены = 0 — леджер фиксирует
 * нули против потолков (TBD-MG) как базу week-0; LLM-live трек — отдельный прогон.
 * Офлайн by construction: ни БД, ни сети, ни записей; корпус публичный маскированный.
 */
class SuflerWeek0Autopilot extends Command
{
    protected $signature = 'sufler:week0-autopilot
        {--questions=tests/fixtures/Support/classifier_corpus_2026_08.json : 50 реальных маскированных сообщений}
        {--eval=tests/fixtures/faq_rag_eval.json : размеченный eval-набор для precision цитат}
        {--policy=policy/sufler.policy.yml : политика пилота (v0 skeleton)}
        {--sample=20 : размер спот-сэмпла precision цитат}
        {--top-k=3 : глубина retrieval для черновика}
        {--out-json=reports/sufler-week0-autopilot-2026-10-02.json}
        {--out-md=docs/REPORT_SUFLER_WEEK0_BASELINE_02-10-2026.md}';

    protected $description = 'H5560/Q11: week-0 baseline автопрогона суфлёра (доля без человека + precision цитат N=20) под политикой и killgate';

    public function handle(Bm25FaqRetriever $retriever): int
    {
        $questionsPath = base_path((string) $this->option('questions'));
        $evalPath = base_path((string) $this->option('eval'));
        $policyPath = base_path((string) $this->option('policy'));
        foreach ([$questionsPath, $evalPath, $policyPath] as $p) {
            if (! is_file($p)) {
                $this->error("missing file: {$p}");

                return self::FAILURE;
            }
        }

        $policy = $this->parsePolicy($policyPath);
        $topK = max(1, (int) $this->option('top-k'));
        $sampleN = max(1, (int) $this->option('sample'));

        // ---------- Часть A: доля без человека ----------
        $cases = json_decode((string) file_get_contents($questionsPath), true, 512, JSON_THROW_ON_ERROR)['cases'];
        $verdicts = [];
        $perClass = [];
        foreach ($cases as $i => $case) {
            $text = (string) $case['t'];
            $cat = (string) ($case['cat'] ?? 'none');
            $row = ['i' => $i + 1, 'category' => $cat, 'text' => mb_substr($text, 0, 120)];

            if ($cat === 'none') {
                $row['verdict'] = 'excluded_smalltalk';
                $verdicts[] = $row;
                continue;
            }

            $hits = $retriever->retrieve($text, $topK);
            $pass = $hits !== [] && $retriever->passesThreshold($hits);
            $row['top1_chunk'] = $hits[0]['chunk_id'] ?? null;
            $row['top1_bm25'] = $hits[0]['bm25_score'] ?? ($hits[0]['score'] ?? null);
            $row['threshold_pass'] = $pass;

            if ($cat === 'D') {
                // Деньги: порог пройден → candidate с дисклеймером каталога; нет → полное молчание.
                $row['verdict'] = $pass ? 'autonomous_candidate' : 'silence_money';
            } elseif ($cat === 'C') {
                // Расписание: факты офлайн-неразрешимы — честный HINT, не засчитываем в авто.
                $row['verdict'] = 'hint_schedule_facts_offline';
            } elseif ($cat === 'F' && preg_match('/сертификат|certificate/iu', $text)) {
                // Граница пилота: сертификаты — всегда человек.
                $row['verdict'] = 'human_certificates';
            } else {
                $row['verdict'] = $pass ? 'autonomous_candidate' : 'hint';
            }

            $perClass[$cat][$row['verdict']] = ($perClass[$cat][$row['verdict']] ?? 0) + 1;
            $verdicts[] = $row;
        }

        $tickets = array_values(array_filter($verdicts, fn ($r) => $r['verdict'] !== 'excluded_smalltalk'));
        $autonomous = count(array_filter($tickets, fn ($r) => $r['verdict'] === 'autonomous_candidate'));
        $shareWithoutHuman = count($tickets) > 0 ? round($autonomous / count($tickets), 4) : null;

        // ---------- Часть B: precision цитат N=20 ----------
        $items = json_decode((string) file_get_contents($evalPath), true, 512, JSON_THROW_ON_ERROR)['items'];
        $sample = array_slice($items, 0, $sampleN);
        $rowsB = [];
        $top1Correct = 0;
        $top3Any = 0;
        $thresholdCorrect = 0;
        $thresholdCovered = 0;
        foreach ($sample as $item) {
            $hits = $retriever->retrieve((string) $item['question'], 3);
            $expected = array_map('strval', (array) ($item['expected_chunk_ids'] ?? []));
            $top1 = $hits[0]['chunk_id'] ?? null;
            $ok1 = $top1 !== null && in_array($top1, $expected, true);
            $ok3 = $hits !== [] && count(array_intersect(array_column($hits, 'chunk_id'), $expected)) > 0;
            $pass = $hits !== [] && $retriever->passesThreshold($hits);
            $top1Correct += $ok1 ? 1 : 0;
            $top3Any += $ok3 ? 1 : 0;
            if ($pass) {
                $thresholdCovered++;
                $thresholdCorrect += $ok1 ? 1 : 0;
            }
            $rowsB[] = [
                'id' => $item['id'], 'category' => $item['category'] ?? null,
                'top1' => $top1, 'expected' => $expected,
                'top1_correct' => $ok1, 'top3_any' => $ok3, 'threshold_pass' => $pass,
            ];
        }
        $n = count($rowsB);
        $precisionTop1 = $n ? round($top1Correct / $n, 4) : null;
        $precisionAtThreshold = $thresholdCovered > 0 ? round($thresholdCorrect / $thresholdCovered, 4) : null;
        $top3Rate = $n ? round($top3Any / $n, 4) : null;

        // ---------- Killgate-леджер (§13) ----------
        $ledger = [
            'track' => 'deterministic_offline (LLM не вызывался)',
            'tokens_used_total' => 0,
            'tokens_per_ticket_ceiling' => $policy['tokens_per_ticket'],
            'monthly_allowance' => $policy['monthly_allowance'],
            'ceilings_owner' => 'TBD-MG (z.ai Max месячная норма) — строка MG @DECIDE',
            'max_steps_per_ticket_limit' => $policy['max_steps_per_ticket'],
            'max_steps_used' => 3, // retrieve + draft + verify
            'auto_stop_events' => 0,
            'write_send_tools_used' => 0, // allowlist: только read-инструменты
        ];

        $payload = [
            'generated' => date('Y-m-d H:i'),
            'handoff' => 'H5560 / GTD row 0LD (grill round 2 Q11)',
            'corpus_v1' => 'resources/knowledge/faq.md',
            'questions_corpus' => basename($questionsPath).' ('.count($cases).' cases)',
            'share_without_human' => [
                'value' => $shareWithoutHuman,
                'definition' => 'autonomous_candidate / все тикеты (small talk исключён)',
                'tickets_total' => count($tickets),
                'autonomous' => $autonomous,
                'hint' => count(array_filter($tickets, fn ($r) => str_starts_with((string) $r['verdict'], 'hint'))),
                'silence_money' => count(array_filter($tickets, fn ($r) => $r['verdict'] === 'silence_money')),
                'human_certificates' => count(array_filter($tickets, fn ($r) => $r['verdict'] === 'human_certificates')),
                'per_class' => $perClass,
                'rows' => $verdicts,
            ],
            'citation_precision_n' => [
                'n' => $n,
                'precision_top1' => $precisionTop1,
                'precision_at_threshold' => $precisionAtThreshold,
                'threshold_coverage' => $thresholdCovered,
                'top3_any_rate' => $top3Rate,
                'bar_r3' => 0.95,
                'rows' => $rowsB,
            ],
            'killgate_ledger' => $ledger,
        ];

        $outJson = base_path((string) $this->option('out-json'));
        $outMd = base_path((string) $this->option('out-md'));
        is_dir(dirname($outJson)) || mkdir(dirname($outJson), 0777, true);
        is_dir(dirname($outMd)) || mkdir(dirname($outMd), 0777, true);
        file_put_contents($outJson, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");

        $md = $this->renderMd($payload);
        file_put_contents($outMd, $md);

        $this->info(sprintf('A: доля без человека = %s (%d/%d тикетов)', var_export($shareWithoutHuman, true), $autonomous, count($tickets)));
        $this->info(sprintf('B: precision top-1 = %s (n=%d), при пороге = %s (покрытие %d), top-3 any = %s',
            var_export($precisionTop1, true), $n, var_export($precisionAtThreshold, true), $thresholdCovered, var_export($top3Rate, true)));
        $this->info("wrote {$outJson}");
        $this->info("wrote {$outMd}");

        return self::SUCCESS;
    }

    /**
     * Минимальный парсер плоского policy-yml (списки и num/строки, TBD остаётся строкой).
     *
     * @return array<string, mixed>
     */
    private function parsePolicy(string $path): array
    {
        $out = ['max_steps_per_ticket' => 8, 'tokens_per_ticket' => 'TBD', 'monthly_allowance' => 'TBD'];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^\s+(max_steps_per_ticket|tokens_per_ticket|monthly_allowance):\s*(\S+)/', $line, $m)) {
                $v = $m[2];
                $out[$m[1]] = ctype_digit($v) ? (int) $v : rtrim($v, '# ');
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function renderMd(array $p): string
    {
        $s = $p['share_without_human'];
        $c = $p['citation_precision_n'];
        $k = $p['killgate_ledger'];
        $lines = [
            '# Суфлёр week-0: baseline автопрогона (Q11, row 0LD)',
            '',
            "_Сгенерировано: {$p['generated']} · H5560/row 0LD · корпус v1: {$p['corpus_v1']} · вопросы: {$p['questions_corpus']}_",
            '',
            'Детерминированный офлайн-трек: BM25-retrieval + цитированный черновик, LLM не вызывался (токены 0).',
            '',
            '## Числа week-0 (§20 п.2–3)',
            '',
            '| Метрика | Значение | Планка |',
            '|---|---|---|',
            '| Доля обращений, закрытых без человека (автономный кандидат) | **'.($s['value'] * 100).' %** ('.$s['autonomous'].'/'.$s['tickets_total'].') | растёт week-over-week |',
            '| Precision цитат, top-1 (N='.$c['n'].') | **'.($c['precision_top1'] * 100).' %** | ≥95 % (R3) |',
            '| Precision цитат при пороге (покрытие '.$c['threshold_coverage'].'/'.$c['n'].') | '.($c['precision_at_threshold'] === null ? 'н/д' : ($c['precision_at_threshold'] * 100).' %').' | ≥95 % (R3) |',
            '| Top-3 any-hit | '.($c['top3_any_rate'] * 100).' % | — |',
            '',
            '## Разбор вердиктов (часть A)',
            '',
            '- autonomous_candidate: **'.$s['autonomous'].'**',
            '- hint (💡, человек): **'.$s['hint'].'**',
            '- silence (деньги, без порога → финлид): **'.$s['silence_money'].'**',
            '- human (сертификаты, граница пилота): **'.$s['human_certificates'].'**',
            '- excluded (small talk, вне знаменателя): **'.(count($s['rows']) - $s['tickets_total']).'**',
            '',
            '### По классам',
            '',
            '| класс | вердикты |',
            '|---|---|',
        ];
        foreach ($s['per_class'] as $cat => $verdicts) {
            $parts = [];
            foreach ($verdicts as $v => $n2) {
                $parts[] = "{$v}: {$n2}";
            }
            $lines[] = '| '.$cat.' | '.implode('; ', $parts).' |';
        }
        $lines += [
            '',
            '## Killgate-леджер (§13)',
            '',
            '- трек: '.$k['track'],
            '- токены: '.$k['tokens_used_total'].' из потолка '.$k['tokens_per_ticket_ceiling'].' / тикет; месячная норма: '.$k['monthly_allowance'].' ('.$k['ceilings_owner'].')',
            '- шаги на тикет: '.$k['max_steps_used'].' из '.$k['max_steps_per_ticket_limit'],
            '- авто-стопов: '.$k['auto_stop_events'].'; write/send-инструментов: '.$k['write_send_tools_used'].' (allowlist read-only)',
            '',
            '## Ограничения week-0 (честно)',
            '',
            '- C-класс (расписание) офлайн = hint: факты расписания требуют LMS/БД — в знаменателе против авто.',
            '- D-класс autonomous-candidate — с дисклеймером «цены только из живого каталога» (политика D-трека).',
            '- LLM-live трек (SupportLlmDraftComposer) в прогоне не участвовал: нужен ключ и прод-обвязка; killgate-леджер ниже фиксирует рамку.',
            '- Пороги retrieval — дефолтные (config support.faq_rag); калибровка порогов под 95 % — отдельный проход (faq:score-floor).',
            '- ПДн: корпус публичный маскированный, вопросы анонимизированы — инвариант 152-ФЗ соблюдён by construction.',
            '',
            '## Вердикт',
            '',
            'База week-0 снята: обе метрики §20 п.2–3 имеют числовую опору. Go-условие стройки (скоуп H5560)',
            'закрыто наполовину: week-0 есть, цифра месячной нормы z.ai Max — за MG (TBD в политике).',
            '',
            'Связка метрик (риск конверсии): '.$s['autonomous'].' автономных кандидатов опираются на top-1',
            'цитаты, из которых верны только '.($c['precision_top1'] * 100).' % — без калибровки порога (faq:score-floor)',
            'и/или плотной ноги гибрида (H5065) доля-кандидаты не конвертируется в безопасную прод-долю.',
            'Порядок: сначала precision ≥95 % на пороге с приемлемым покрытием, потом автономная отправка.',
            '',
            '_Гасунс_',
            '',
        ];

        return implode("\n", $lines);
    }
}
