<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Faq\HybridRetriever;
use App\Services\Support\SupportAnswerSuggester;
use App\Services\Support\SupportDmAutoReply;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * H4001 wiring-guard: lane-потребители сидят на HybridRetriever (дроп-ин над
 * BM25). При flag OFF гибрид байт-в-байт равен BM25 (FaqHybridRetrieverTest),
 * при ON — fusion. Тест ловит случайный откат type-hint на голый Bm25, при
 * котором флаг faq_hybrid_retrieval стал бы декоративным.
 */
class FaqHybridWiringTest extends TestCase
{
    public function test_lane_consumers_depend_on_hybrid_retriever(): void
    {
        $expectations = [
            SupportAnswerSuggester::class => 'faqRag',
            SupportDmAutoReply::class => 'faq',
        ];

        foreach ($expectations as $class => $property) {
            $ref = new ReflectionClass($class);
            $this->assertTrue($ref->hasProperty($property), "{$class} lost the retrieval dependency");

            $type = $ref->getProperty($property)->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertSame(
                HybridRetriever::class,
                $type->getName(),
                "{$class}::{$property} must be injected as HybridRetriever (flag faq_hybrid_retrieval must stay wired)",
            );
        }
    }
}
