<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Services\Recordings\N8nZoomExecutionRetrier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3209 residual (23-08-2026 jam): opt-in --retry-failed lane for
 * executions that died before any upload ran.
 */
class RecordingsGapWatchRetryTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $yesterday2000;

    protected function setUp(): void
    {
        parent::setUp();

        $tz = 'Europe/Moscow';
        $this->yesterday2000 = CarbonImmutable::now($tz)->subDay()->setTime(20, 0);

        config([
            'app.timezone' => $tz,
            'services.telegram.bot_token' => 'test-token',
            'recording_gap.telegram_chat_id' => '11111',
            'recording_gap.n8n_api_base' => 'https://context-ai.ru',
            'recording_gap.n8n_api_key' => 'test-n8n-key',
            'recording_gap.n8n_workflow_id' => '1EIqqNzMl5NNIxST',
            'recording_gap.retry_enabled' => false,
            'recording_gap.retry_max_per_run' => 5,
            'recording_gap.skip_title_substrings' => ['Созвон отдела Заботы'],
            'recording_gap.skip_course_ids' => [],
        ]);

        Cache::flush();
    }

    public function test_retry_flag_without_enabled_config_posts_nothing(): void
    {
        Http::fake(['https://context-ai.ru/*' => fn ($request) => $this->probeQuiet()]);
        $this->seedLiveSlot();

        Artisan::call('recordings:gap-watch', ['--dry' => true, '--retry-failed' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('RECORDING_GAP_RETRY_FAILED_ENABLED=false', $out);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/retry'));
    }

    public function test_dry_lists_safe_failure_without_posting_retry(): void
    {
        config(['recording_gap.retry_enabled' => true]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://context-ai.ru/*' => fn ($request) => $this->n8nRoute($request, safeFailure: true),
        ]);
        $this->seedLiveSlot();

        $code = Artisan::call('recordings:gap-watch', ['--dry' => true, '--retry-failed' => true]);
        $out = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('2050', $out);
        $this->assertStringContainsString('был бы отправлен (dry)', $out);
        $this->assertStringContainsString('--dry: ретраи не отправлены.', $out);
        Http::assertSent(fn ($request) => ! ($request->method() === 'POST' && str_contains($request->url(), '/retry')));
    }

    public function test_live_lane_posts_retry_for_safe_early_failure_and_caches_marker(): void
    {
        config(['recording_gap.retry_enabled' => true]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://context-ai.ru/*' => fn ($request) => $this->n8nRoute($request, safeFailure: true),
        ]);
        $this->seedLiveSlot();

        Artisan::call('recordings:gap-watch', ['--retry-failed' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('ретрай отправлен', $out);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/api/v1/executions/2050/retry'));
        $this->assertTrue((bool) Cache::has(N8nZoomExecutionRetrier::CACHE_PREFIX.'2050'));
    }

    public function test_second_run_skips_already_retried_execution(): void
    {
        config(['recording_gap.retry_enabled' => true]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://context-ai.ru/*' => fn ($request) => $this->n8nRoute($request, safeFailure: true),
        ]);
        $this->seedLiveSlot();

        Artisan::call('recordings:gap-watch', ['--retry-failed' => true]);
        Artisan::call('recordings:gap-watch', ['--retry-failed' => true]);
        $second = Artisan::output();

        $posts = collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), '/retry'))
            ->count();
        $this->assertSame(1, $posts);
        $this->assertStringContainsString('уже ретраили ранее', $second);
    }

    public function test_unsafe_late_failure_is_skipped_with_manual_hint(): void
    {
        config(['recording_gap.retry_enabled' => true]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://context-ai.ru/*' => fn ($request) => $this->n8nRoute($request, safeFailure: false),
        ]);
        $this->seedLiveSlot();

        Artisan::call('recordings:gap-watch', ['--retry-failed' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('не безопасен', $out);
        $this->assertStringContainsString('AI Agent1', $out);
        Http::assertSent(fn ($request) => ! ($request->method() === 'POST' && str_contains($request->url(), '/retry')));
    }

    public function test_superseded_failure_is_skipped(): void
    {
        config(['recording_gap.retry_enabled' => true]);
        Http::fake([
            'https://api.telegram.org/*' => Http::response(['ok' => true], 200),
            'https://context-ai.ru/*' => fn ($request) => $this->n8nRoute(
                $request,
                safeFailure: true,
                supersedeWithSuccess: true,
            ),
        ]);
        $this->seedLiveSlot();

        Artisan::call('recordings:gap-watch', ['--retry-failed' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('уже есть успешный ретрай', $out);
        Http::assertSent(fn ($request) => ! ($request->method() === 'POST' && str_contains($request->url(), '/retry')));
    }

    public function test_no_gaps_means_retry_lane_never_runs(): void
    {
        config(['recording_gap.retry_enabled' => true]);
        Http::fake(['https://context-ai.ru/*' => fn ($request) => $this->probeQuiet()]);

        $this->assertSame(0, Artisan::call('recordings:gap-watch', ['--retry-failed' => true]));

        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/retry'));
    }

    public function test_run_data_safety_gate(): void
    {
        $early = ['ZOOM', 'Switch', 'Get row(s) in sheet', 'Get row(s) in sheet1'];
        $late = ['ZOOM', 'DOWNLOAD'];

        $this->assertTrue(N8nZoomExecutionRetrier::isRunDataSafe([]));
        $this->assertTrue(N8nZoomExecutionRetrier::isRunDataSafe($early));
        $this->assertFalse(N8nZoomExecutionRetrier::isRunDataSafe($late));
        $this->assertFalse(N8nZoomExecutionRetrier::isRunDataSafe(['AI Agent1']));
    }

    /**
     * @return array{course: Course, group: Group, schedule: Schedule}
     */
    private function seedLiveSlot(): array
    {
        $course = Course::factory()->live()->create(['title' => 'Курс тест']);
        $group = Group::create([
            'name' => 'Группа А',
            'telegram_chat_id' => '-100999',
        ]);
        $schedule = Schedule::create([
            'title' => 'Live',
            'course_id' => $course->id,
            'group_id' => $group->id,
            'start' => $this->yesterday2000,
        ]);

        return compact('course', 'group', 'schedule');
    }

    private function failedRow(string $id): array
    {
        return [
            'id' => $id,
            'finished' => true,
            'mode' => 'webhook',
            'retryOf' => null,
            'status' => 'error',
            'startedAt' => CarbonImmutable::now('UTC')->subDay()->setTimeFrom($this->yesterday2000)->toIso8601String(),
            'stoppedAt' => CarbonImmutable::now('UTC')->subDay()->addHours(2)->toIso8601String(),
            'workflowId' => '1EIqqNzMl5NNIxST',
            'data' => [
                'resultData' => [
                    'error' => [
                        'message' => 'The connection to the server was closed unexpectedly, perhaps it is offline.',
                        'httpCode' => 'ECONNRESET',
                    ],
                ],
            ],
        ];
    }

    private function detailFor(bool $safeFailure): array
    {
        $runData = [
            'ZOOM' => ['data' => ['main' => [[]]]],
            'Switch' => [],
            'Code in JavaScript1' => [],
            'Respond to Webhook1' => [],
            'Get row(s) in sheet' => ['error' => 'ECONNRESET'],
        ];
        if (! $safeFailure) {
            $runData['Upload a video'] = ['data' => ['main' => [[]]]];
        }

        return [
            'id' => '2050',
            'status' => 'error',
            'startedAt' => $this->failedRow('2050')['startedAt'],
            'workflowId' => '1EIqqNzMl5NNIxST',
            'data' => [
                'resultData' => [
                    'error' => ['message' => 'connection closed'],
                    'lastNodeExecuted' => $safeFailure ? 'Get row(s) in sheet' : 'Upload a video',
                    'runData' => $runData,
                ],
            ],
        ];
    }

    private function n8nRoute($request, bool $safeFailure, bool $supersedeWithSuccess = false)
    {
        $url = (string) $request->url();

        if ($request->method() === 'POST') {
            return Http::response(['id' => '9999'], 200);
        }
        if (preg_match('#/api/v1/executions/\d+#', $url) === 1) {
            return Http::response($this->detailFor($safeFailure), 200);
        }
        if (str_contains($url, 'status=success')) {
            $rows = $supersedeWithSuccess ? [[
                'id' => '2100',
                'finished' => true,
                'mode' => 'retry',
                'retryOf' => ['2050'],
                'status' => 'success',
                'startedAt' => CarbonImmutable::now('UTC')->toIso8601String(),
                'workflowId' => '1EIqqNzMl5NNIxST',
            ]] : [];

            return Http::response(['data' => ['results' => $rows]], 200);
        }
        if (str_contains($url, 'status=error')) {
            return Http::response(['data' => ['results' => [$this->failedRow('2050')]]], 200);
        }

        return $this->probeQuiet();
    }

    private function probeQuiet(): array
    {
        return Http::response(['data' => ['results' => []]], 200);
    }
}
