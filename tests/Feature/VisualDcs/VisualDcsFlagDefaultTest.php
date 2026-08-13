<?php

declare(strict_types=1);

namespace Tests\Feature\VisualDcs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisualDcsFlagDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_flags_default_off(): void
    {
        $this->assertFalse(config('features.visualdcs_verb'));
        $this->assertFalse(config('features.visualdcs_nominal'));
        $this->assertFalse(config('features.visualdcs_passage'));
    }

    public function test_routes_404_when_flags_off(): void
    {
        $this->get('/visualdcs/verb/preview')->assertNotFound();
        $this->get('/visualdcs/nominal/preview')->assertNotFound();
        $this->get('/visualdcs/passage/preview')->assertNotFound();
    }
}
