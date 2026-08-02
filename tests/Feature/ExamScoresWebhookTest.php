<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\ExamScore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamScoresWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-exam-secret';

    private Course $course;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.n8n.exam_scores_secret' => self::SECRET]);

        $this->course = Course::factory()->create();
        $this->student = User::factory()->create(['email' => 'student@example.com']);
    }

    private function send(array $rows, ?string $secret = self::SECRET)
    {
        $headers = $secret !== null ? ['X-Webhook-Secret' => $secret] : [];

        return $this->postJson('/api/webhooks/exam-scores', ['rows' => $rows], $headers);
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'email' => 'student@example.com',
            'course' => $this->course->slug,
            'score_clarity' => 18,
            'score_letters' => 4.5,
            'score_flow' => 5,
            'row_ref' => 'Лист1!A7',
        ], $overrides);
    }

    /** @test */
    public function it_rejects_requests_without_or_with_wrong_secret(): void
    {
        $this->send([$this->row()], null)->assertForbidden();
        $this->send([$this->row()], 'wrong')->assertForbidden();
    }

    /** @test */
    public function it_is_disabled_when_no_secret_is_configured(): void
    {
        config(['services.n8n.exam_scores_secret' => '']);

        $this->send([$this->row()], '')->assertForbidden();
        $this->send([$this->row()], 'anything')->assertForbidden();
    }

    /** @test */
    public function it_creates_scores_matching_email_case_insensitively(): void
    {
        $response = $this->send([$this->row(['email' => 'STUDENT@Example.COM'])]);

        $response->assertOk()->assertJson(['ok' => true, 'created' => 1, 'updated' => 0, 'unchanged' => 0]);

        $score = ExamScore::firstOrFail();
        $this->assertSame($this->student->id, $score->user_id);
        $this->assertSame($this->course->id, $score->course_id);
        $this->assertSame(18.0, $score->score_clarity);
        $this->assertSame(4.5, $score->score_letters);
        $this->assertSame('Лист1!A7', $score->source_row);
        $this->assertNotNull($score->imported_at);
    }

    /** @test */
    public function it_updates_on_retake_and_reports_unchanged_on_replay(): void
    {
        $this->send([$this->row()])->assertJson(['created' => 1]);

        // Пересдача — та же строка, новые баллы.
        $this->send([$this->row(['score_clarity' => 20])])
            ->assertJson(['created' => 0, 'updated' => 1, 'unchanged' => 0]);
        $this->assertSame(20.0, ExamScore::firstOrFail()->score_clarity);
        $this->assertSame(1, ExamScore::count());

        // Идемпотентный повтор того же листа.
        $this->send([$this->row(['score_clarity' => 20])])
            ->assertJson(['created' => 0, 'updated' => 0, 'unchanged' => 1]);
    }

    /** @test */
    public function unmatched_rows_are_returned_with_reasons(): void
    {
        $response = $this->send([
            $this->row(['email' => 'unknown@example.com']),
            $this->row(['course' => 'no-such-course']),
            $this->row(['score_clarity' => null, 'score_letters' => null, 'score_flow' => null]),
        ]);

        $response->assertOk();
        $unmatched = $response->json('unmatched');
        $this->assertCount(3, $unmatched);
        $this->assertSame('user_not_found', $unmatched[0]['reason']);
        $this->assertSame('course_not_found', $unmatched[1]['reason']);
        $this->assertSame('empty_scores', $unmatched[2]['reason']);
        $this->assertSame(0, ExamScore::count());
    }

    /** @test */
    public function out_of_range_scores_fail_validation(): void
    {
        $this->send([$this->row(['score_clarity' => 21])])->assertStatus(422);
        $this->send([$this->row(['score_letters' => 5.5])])->assertStatus(422);
        $this->send([$this->row(['score_flow' => 'abc'])])->assertStatus(422);
    }
}
