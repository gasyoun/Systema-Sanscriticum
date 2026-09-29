<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Support;

use App\Services\Support\SupportFactCheckVerifier;
use PHPUnit\Framework\TestCase;

class SupportFactCheckVerifierTest extends TestCase
{
    public function test_matching_price_and_link_pass(): void
    {
        $verifier = new SupportFactCheckVerifier;

        $result = $verifier->verify(
            'Остаток по оплате — 15 000 ₽. Ссылка на занятие: https://zoom.us/j/123456',
            ['type' => 'balance', 'amount_due' => 15000.0, 'link' => 'https://zoom.us/j/123456'],
        );

        $this->assertSame(SupportFactCheckVerifier::STATUS_MATCH, $result['status']);
        $this->assertSame([], $result['mismatches']);
        $this->assertSame(['price', 'link'], $result['checked']);
    }

    public function test_price_mismatch_is_flagged(): void
    {
        $verifier = new SupportFactCheckVerifier;

        $result = $verifier->verify(
            'С вас осталось доплатить 20 000 ₽.',
            ['type' => 'balance', 'amount_due' => 15000.0],
        );

        $this->assertSame(SupportFactCheckVerifier::STATUS_MISMATCH, $result['status']);
        $this->assertCount(1, $result['mismatches']);
        $this->assertSame('price', $result['mismatches'][0]['type']);
        $this->assertSame(20000.0, $result['mismatches'][0]['claimed']);
        $this->assertSame([15000.0], $result['mismatches'][0]['known']);
    }

    public function test_link_mismatch_is_flagged(): void
    {
        $verifier = new SupportFactCheckVerifier;

        $result = $verifier->verify(
            'Ссылка для подключения: https://zoom.us/j/999999',
            ['type' => 'zoom', 'link' => 'https://zoom.us/j/123456'],
        );

        $this->assertSame(SupportFactCheckVerifier::STATUS_MISMATCH, $result['status']);
        $this->assertSame('link', $result['mismatches'][0]['type']);
    }

    public function test_no_verifiable_claims_is_unverifiable(): void
    {
        $verifier = new SupportFactCheckVerifier;

        $result = $verifier->verify(
            'Ближайшие занятия вашей группы: понедельник и среда.',
            ['type' => 'schedule'],
        );

        $this->assertSame(SupportFactCheckVerifier::STATUS_UNVERIFIABLE, $result['status']);
        $this->assertSame([], $result['mismatches']);
    }

    public function test_claim_with_no_known_source_is_not_a_hard_mismatch(): void
    {
        // Резолвер не дал денежных фактов (например категория E/F) — мы не
        // умеем сверить утверждение LLM и честно это не скрываем как "match".
        $verifier = new SupportFactCheckVerifier;

        $result = $verifier->verify(
            'Стоимость сертификата — 500 ₽.',
            ['type' => 'access'],
        );

        $this->assertSame(SupportFactCheckVerifier::STATUS_UNVERIFIABLE, $result['status']);
        $this->assertSame([], $result['mismatches']);
    }

    public function test_amount_below_floor_is_ignored(): void
    {
        $verifier = new SupportFactCheckVerifier;

        $result = $verifier->verify(
            'Осталось 3 занятия до конца блока.',
            ['type' => 'schedule'],
        );

        $this->assertSame(SupportFactCheckVerifier::STATUS_UNVERIFIABLE, $result['status']);
    }
}
