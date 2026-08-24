<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TrialBookToken;
use Tests\TestCase;

class TrialBookTokenTest extends TestCase
{
    public function test_round_trip_returns_schedule_id(): void
    {
        $token = TrialBookToken::for(42);

        $this->assertSame(42, TrialBookToken::resolve($token));
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $token = TrialBookToken::for(42);
        $tampered = substr($token, 0, strrpos($token, '.')).'.AAAA';

        $this->assertNull(TrialBookToken::resolve($tampered));
    }

    public function test_id_swap_with_foreign_signature_is_rejected(): void
    {
        $token = TrialBookToken::for(42);
        $signature = substr($token, strrpos($token, '.') + 1);

        $this->assertNull(TrialBookToken::resolve('43.'.$signature));
    }

    public function test_garbage_tokens_are_rejected(): void
    {
        $this->assertNull(TrialBookToken::resolve(''));
        $this->assertNull(TrialBookToken::resolve('nodot'));
        $this->assertNull(TrialBookToken::resolve('.nodot.'));
        $this->assertNull(TrialBookToken::resolve('id.nodigit.signature'));
    }
}
