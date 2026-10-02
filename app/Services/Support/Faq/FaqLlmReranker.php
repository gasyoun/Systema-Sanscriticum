<?php

declare(strict_types=1);

namespace App\Services\Support\Faq;

use App\Services\Bot\CuratorAi;

/**
 * H5648 — LLM-rerank top-K цитат суфлёра. После retrieval (bm25|hybrid) модель
 * SupportLlmDraftComposer-стека (CuratorAi, локальная нога localChatWithUsage →
 * knowledge.base_url) выбирает РОВНО ОДИН фрагмент из кандидатов: top-3-покрытие
 * гибрида 100 % (проба H5065), а top-1 дискриминация — 65 % против планки R3
 * ≥95 %; веса RRF — не рычаг (sweep H4001).
 *
 * Ошибки мягкие: null или не разобранный ответ = fallback на порядок retrieval
 * (реранк не может опуститься ниже пола — worst case байт-в-байт база). Токены
 * каждого вызова возвращаются наружу для killgate-леджера (§13): реранк обязан
 * платить токенами, даже когда промахнулся. Нового HTTP-клиента здесь нет
 * intentionally — только CuratorAi.
 */
final class FaqLlmReranker
{
    private const MAX_SNIPPET_CHARS = 1500;

    public function __construct(private readonly CuratorAi $ai) {}

    /**
     * Выбрать лучший фрагмент из кандидатов retrieval.
     *
     * @param  list<array{chunk_id: string, title: string, heading_path: list<string>, snippet: string, source: string}>  $hits
     * @return array{pick: ?string, rank: ?int, usage: ?array{prompt_tokens: int, completion_tokens: int}, model: ?string, fallback: bool, raw: ?string}
     */
    public function rerank(string $question, array $hits): array
    {
        if ($hits === []) {
            return ['pick' => null, 'rank' => null, 'usage' => null, 'model' => null, 'fallback' => true, 'raw' => null];
        }

        $result = $this->ai->localChatWithUsage([
            ['role' => 'system', 'content' => $this->systemPrompt()],
            ['role' => 'user', 'content' => $this->userPrompt($question, $hits)],
        ]);

        $pick = null;
        $rank = null;
        $fallback = true;
        $raw = $result['content'];
        if ($raw !== null && preg_match_all('/Ответ\s*[:\-]?\s*([A-Z])\b/u', $raw, $ms, PREG_SET_ORDER)) {
            // CoT-формат: буква живёт в строке-вердикте «Ответ: X» (последняя —
            // финальная, если модель продублировала).
            $idx = ord(end($ms)[1]) - ord('A');
            if ($idx >= 0 && $idx < count($hits)) {
                $pick = $hits[$idx]['chunk_id'];
                $rank = $idx;
                $fallback = false;
            }
        }
        if ($pick === null && $raw !== null && preg_match('/\b([A-Z])\b/u', mb_substr($raw, 0, 24), $m)) {
            $idx = ord($m[1]) - ord('A');
            if ($idx >= 0 && $idx < count($hits)) {
                $pick = $hits[$idx]['chunk_id'];
                $rank = $idx;
                $fallback = false;
            }
        }

        // Фолбэк — порядок retrieval: реранк честно отказался, пол не трогаем.
        if ($pick === null) {
            $pick = $hits[0]['chunk_id'] ?? null;
            $rank = $pick === null ? null : 0;
        }

        return ['pick' => $pick, 'rank' => $rank, 'usage' => $result['usage'], 'model' => $result['model'], 'fallback' => $fallback, 'raw' => $raw];
    }

    private function systemPrompt(): string
    {
        return 'Ты — реранкер суфлёра поддержки онлайн-школы. Тебе дают вопрос студента '
            ."и фрагменты FAQ, каждый помечен своей буквой (A, B, C…).\n"
            .'Порядок разбора: по одному фрагменту в строке — буква и вердикт, отвечает '
            .'ли он ПРЯМО на вопрос («прямо», «упоминает попутно», «нет»). Прямой ответ — '
            .'перечень, инструкция или название того, о чём спросили; фрагмент, где тема '
            .'лишь упомянута внутри другого ответа, прямым НЕ является. Если прямого '
            ."ответа нет ни в одном — бери самый близкий по теме.\n"
            ."Пример разбора. Вопрос: «Сколько длится блок занятий и как он оплачивается?»\n"
            ."A — упоминает попутно (оплата названа среди прочего, длительности нет)\n"
            ."B — прямо (блок = 4 занятия, оплата поблочно)\n"
            ."C — нет (про тетради)\n"
            ."Ответ: B\n"
            .'Последней строкой ВСЕГДА ровно «Ответ: X», где X — буква выбранного '
            .'фрагмента. Никаких других строк после неё.';
    }

    /**
     * @param  list<array{title: string, heading_path: list<string>, snippet: string}>  $hits
     */
    private function userPrompt(string $question, array $hits): string
    {
        $blocks = [];
        foreach ($hits as $i => $hit) {
            $letter = chr(ord('A') + $i);
            $heading = implode(' / ', (array) ($hit['heading_path'] ?? []));
            $snippet = mb_substr((string) ($hit['snippet'] ?? ''), 0, self::MAX_SNIPPET_CHARS);
            $blocks[] = "[{$letter}] ".trim(($hit['title'] ?? '').' · '.$heading)."\n{$snippet}";
        }

        return "Вопрос студента:\n".$question
            ."\n\nФрагменты FAQ:\n\n".implode("\n\n", $blocks)
            ."\n\nРазбери каждый фрагмент и закончи строкой «Ответ: X».";
    }
}
