<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Models\Announcement;
use App\Models\Course;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\MagicLinkToken;
use App\Models\Payment;
use App\Models\User;
use App\Services\Access\StudentUnblockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H5297 — invariant mutation-testing wave 2 (extends H5094/PR #2683 discipline
 * to three seams it did not cover):
 *
 * A. Authorization seam — GatedAssetController::transcript (paid lecture text
 *    served only through the LessonGate chain; the video gate is covered by
 *    RecordingGateTest, the FILE endpoints were not).
 * B. Capability/secret-exposure seam — MagicLinkToken hash-at-rest (one-time
 *    use and purpose fence are covered; the "DB dump is not a capability"
 *    invariant was not).
 * C. Output-encoding seam — student messages page passes staff-authored
 *    announcement HTML through SanitizedHtml (the sanitizer class is tested
 *    directly by XssRenderSanitizerTest; the page-level pass-through was not).
 *
 * Each test asserts: expected result + forbidden side effect + persisted-state
 * invariant. Mutation receipts: docs/H5297_MUTATION_LEDGER_INVARIANT_WAVE2_23-09-2026.md.
 */
class H5297MutationInvariantWave2Test extends TestCase
{
    use RefreshDatabase;

    private const PAID_TRANSCRIPT_BODY = '{"title":"Песнь о Бхагаватте","body":"H5297-PAID-TRANSCRIPT-TEXT секрет платной лекции"}';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();
        config()->set('features.membership_recording_enforce', false);
        config()->set('features.membership_tiered', true);
        config()->set('features.club_membership', true);
    }

    /**
     * A — authorization: paid transcript is served ONLY to a payer.
     * Expected: group member who never paid → 404; payer → 200 with the text.
     * Forbidden side effect: denial leaks zero transcript bytes.
     * Persisted state: a denial never materializes an access grant (Payment
     * rows and group attachments unchanged by the 404).
     *
     * @test
     */
    public function transcript_file_gate_denies_a_non_payer_and_leaks_nothing(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('transcripts/h5297-paid.json', self::PAID_TRANSCRIPT_BODY);

        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Поток '.$course->id]);
        $course->groups()->attach($group->id);

        $lesson = Lesson::factory()->create([
            'course_id' => $course->id,
            'group_id' => $group->id,
            'block_number' => 1,
            'transcript_file' => 'transcripts/h5297-paid.json',
        ]);

        $student = User::factory()->create();
        // Группа курса даёт видимость, но не оплату — гейт должен отказать.
        $student->groups()->syncWithoutDetaching([$group->id]);

        $denied = $this->actingAs($student)
            ->get(route('student.lesson.transcript', [$course->slug, $lesson->id]));

        $denied->assertNotFound();
        $this->assertStringNotContainsString(
            'H5297-PAID-TRANSCRIPT-TEXT',
            $denied->getContent(),
            'Denial response must not leak paid transcript bytes.',
        );

        // Persisted-state invariant: отказ не порождает грантов.
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(1, $student->fresh()->groups()->count());

        // Expected result: плательщик получает файл.
        Payment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'amount' => 6000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->actingAs($student)
            ->get(route('student.lesson.transcript', [$course->slug, $lesson->id]))
            ->assertOk()
            ->assertSee('H5297-PAID-TRANSCRIPT-TEXT', false);
    }

    /**
     * B — capability/secret exposure: the unblock magic link is a bearer
     * capability and must exist as plaintext ONLY in the issued link.
     * Expected: the plaintext link logs the student in.
     * Forbidden side effect: the value stored at rest is not itself a working
     * capability (GET /login-link/<stored-hash> → 404, still guest).
     * Persisted state: magic_link_tokens.token_hash never equals the plaintext
     * token and equals its SHA-256 — a leaked DB dump cannot be replayed.
     *
     * @test
     */
    public function unblock_link_token_is_never_stored_as_a_plaintext_capability(): void
    {
        $student = User::factory()->create();

        $token = MagicLinkToken::issueFor($student, StudentUnblockService::MAGIC_PURPOSE);
        $this->assertNotSame('', $token);

        // Persisted-state invariant: at rest — только hash.
        $this->assertDatabaseMissing('magic_link_tokens', ['token_hash' => $token]);
        $stored = MagicLinkToken::query()->latest('id')->firstOrFail()->token_hash;
        $this->assertSame(hash('sha256', $token), $stored);

        // Forbidden side effect: сохранённое значение — не работающий логин.
        $this->get('/login-link/'.$stored)->assertNotFound();
        $this->assertGuest();

        // Expected result: plaintext-ссылка работает ровно один раз.
        $this->get('/login-link/'.$token)->assertRedirect(route('student.dashboard'));
        $this->assertAuthenticatedAs($student);
    }

    /**
     * C — output encoding: staff-authored announcement HTML reaches the
     * student messages page only through the whitelist sanitizer.
     * Expected: the safe text of a published announcement is visible.
     * Forbidden side effect: script/iframe payloads, on* attributes and
     * javascript: hrefs never render raw; unpublished drafts never render.
     * Persisted state: the GET never mutates announcement rows (content and
     * is_published unchanged for both).
     *
     * @test
     */
    public function staff_announcement_html_is_sanitized_on_the_messages_page(): void
    {
        $student = User::factory()->create();

        $content = '<p>Расписание на неделю опубликовано</p>'
            .'<script>window.location="https://evil.example/steal"</script>'
            .'<img src=x onerror="fetch(\'/steal\')">'
            .'<a href="javascript:alert(1)">bad link</a>';

        $published = Announcement::create([
            'title' => 'H5297 объявление',
            'content' => $content,
            'is_published' => true,
        ]);

        $draft = Announcement::create([
            'title' => 'H5297 черновик',
            'content' => '<p>H5297-DRAFT-MARKER</p>',
            'is_published' => false,
        ]);

        $response = $this->actingAs($student)->get(route('student.messages'));

        // Expected result: безопасный текст опубликованного виден.
        $response->assertOk()
            ->assertSee('Расписание на неделю опубликовано')
            // Forbidden side effect: payload не попадает в ответ в сыром виде.
            ->assertDontSee('<script', false)
            ->assertDontSee('window.location', false)
            ->assertDontSee('onerror', false)
            ->assertDontSee('javascript:', false)
            // Неопубликованное никогда не рендерится.
            ->assertDontSee('H5297-DRAFT-MARKER', false);

        // Persisted-state invariant: чтение страницы ничего не мутирует.
        $this->assertSame($content, $published->fresh()->content);
        $this->assertTrue($published->fresh()->is_published);
        $this->assertFalse($draft->fresh()->is_published);
        $this->assertSame('<p>H5297-DRAFT-MARKER</p>', $draft->fresh()->content);
    }
}
