<?php

declare(strict_types=1);

namespace Tests\Feature\Support\StudentAgent;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentAgentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_404_when_flag_off(): void
    {
        config()->set('features.student_agent', false);
        $student = User::factory()->create();

        $this->actingAs($student)
            ->postJson('/dvaram/agent', ['tool' => 'dictionary_lookup', 'params' => ['query' => 'agni']])
            ->assertNotFound();
    }

    public function test_route_requires_auth(): void
    {
        config()->set('features.student_agent', true);

        // JSON request through the `auth` middleware: 401, not a login redirect.
        $this->postJson('/dvaram/agent', ['tool' => 'dictionary_lookup', 'params' => ['query' => 'agni']])
            ->assertUnauthorized();
    }

    public function test_route_refuses_out_of_scope_tool(): void
    {
        config()->set('features.student_agent', true);
        $student = User::factory()->create();

        $this->actingAs($student)
            ->postJson('/dvaram/agent', ['tool' => 'free_chat', 'params' => []])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'reason' => 'tool_not_allowed']);
    }

    public function test_route_runs_dictionary_lookup_when_flag_on(): void
    {
        config()->set('features.student_agent', true);
        $student = User::factory()->create();

        $dictionary = Dictionary::create(['name' => 'MW', 'is_active' => true]);
        DictionaryWord::create([
            'dictionary_id' => $dictionary->id,
            'devanagari' => 'अग्नि',
            'iast' => 'agni',
            'cyrillic' => 'агни',
            'translation' => 'огонь',
        ]);

        $this->actingAs($student)
            ->postJson('/dvaram/agent', ['tool' => 'dictionary_lookup', 'params' => ['query' => 'agni']])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.hits.0.iast', 'agni');
    }
}
