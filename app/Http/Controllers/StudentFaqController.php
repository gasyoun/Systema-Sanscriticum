<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Support\Faq\FaqChunk;
use App\Services\Support\Faq\FaqCorpusParser;
use Illuminate\View\View;

/**
 * Веб-паритет FAQ кабинета (H6301, тикет 5 аудита SELF_SERVICE_SUPPORT_UX_AUDIT_2026):
 * та же каноническая база resources/knowledge/faq.md (+ лекционный сосед), что
 * кормит BotKnowledgeBase TG/VK-бота, отрендеренная статичной страницей с
 * категориями и фильтром — БЕЗ второй копии ответов и БЕЗ LLM. Правки источника
 * подхватываются сами (парсер кэшируется 10 минут, как у бота). Ответа нет —
 * существующий маршрут поддержки: вкладка «Поддержка» (#chat), «позови куратора».
 */
class StudentFaqController extends Controller
{
    public function show(FaqCorpusParser $parser): View
    {
        /** @var array<string, list<FaqChunk>> $categories */
        $categories = [];

        foreach ($parser->chunks() as $chunk) {
            $category = $chunk->headingPath[0] ?? 'FAQ';
            $categories[$category][] = $chunk;
        }

        return view('student.faq', [
            'categories' => $categories,
            'sourceNames' => array_values(array_unique(array_map(
                static fn (FaqChunk $c): string => $c->source,
                array_merge(...array_values($categories) ?: [[]]),
            ))),
        ]);
    }
}
