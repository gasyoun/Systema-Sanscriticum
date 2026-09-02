<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Recordings\N8nZoomExecutionProbe;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3952: a per-account fresh-link failure used to be indistinguishable from «вебхук не
 * пришёл» — the ZOOM 1.4 run exited green, so every diagnostic pointed at a missing
 * webhook while the real fault was credential-scoped (Uprava FINDINGS §608).
 *
 * The workflow now fails the run and stamps a verdict marker into the thrown error; these
 * tests pin the alert half: gap-watch must turn that marker into a *different* human
 * verdict per class, and must corroborate it by HEADing the signed webhook token URL —
 * a live token proves the recording still exists, so the failure is a fetch problem.
 */
class RecordingsGapFreshLinkVerdictTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'Europe/Moscow',
            'services.telegram.bot_token' => 'test-token',
            'recording_gap.telegram_chat_id' => '11111',
            'recording_gap.n8n_api_base' => 'https://context-ai.ru',
            'recording_gap.n8n_api_key' => 'test-n8n-key',
            'recording_gap.n8n_workflow_id' => '1EIqqNzMl5NNIxST',
        ]);
    }

    /**
     * The five markers the workflow's «Стоп: …» Code nodes can throw. This test IS the
     * contract: if a marker is renamed on one side only, it lands here.
     */
    public function test_each_workflow_marker_maps_to_its_own_class_and_verdict(): void
    {
        $cases = [
            'H3952_CREDENTIAL_FETCH_FAILURE | verdict=credential_fetch_failure' => 'fresh_link_credential',
            'H3952_WEBHOOK_MISSING | verdict=webhook_missing' => 'fresh_link_webhook_missing',
            'H3952_UNDECIDABLE_3301 | verdict=undecidable_3301' => 'fresh_link_undecidable',
            'H3952_ACCOUNT_UNREGISTERED | verdict=account_unregistered' => 'fresh_link_account_unregistered',
            'H3952_REPLAY_IMPOSSIBLE | verdict=replay_impossible' => 'fresh_link_replay_impossible',
        ];

        $verdicts = [];
        foreach ($cases as $blob => $expectedClass) {
            $this->assertSame($expectedClass, N8nZoomExecutionProbe::classify($blob), $blob);

            $verdict = N8nZoomExecutionProbe::verdictFor($expectedClass);
            $this->assertNotNull($verdict, "no human verdict for {$expectedClass}");
            $verdicts[] = $verdict;
        }

        // The whole point is that these read differently to a duty agent.
        $this->assertSame($verdicts, array_unique($verdicts), 'two classes share one verdict');
    }

    public function test_credential_failure_and_webhook_missing_are_not_the_same_verdict(): void
    {
        $credential = N8nZoomExecutionProbe::verdictFor('fresh_link_credential');
        $missing = N8nZoomExecutionProbe::verdictFor('fresh_link_webhook_missing');

        $this->assertNotSame($credential, $missing);
        $this->assertStringContainsString('credential', (string) $credential);
        $this->assertStringContainsString('записи нет', (string) $missing);
    }

    public function test_h3952_marker_wins_over_the_generic_openrouter_classes(): void
    {
        // A failed run can carry both an OpenRouter phrase and our marker; the marker is
        // the specific diagnosis and must not be shadowed by the generic one.
        $blob = 'Terms of Service ... H3952_CREDENTIAL_FETCH_FAILURE | verdict=credential_fetch_failure';

        $this->assertSame('fresh_link_credential', N8nZoomExecutionProbe::classify($blob));
    }

    public function test_unrelated_failures_keep_their_existing_classes(): void
    {
        $this->assertSame('credits', N8nZoomExecutionProbe::classify('you can only afford 10 tokens'));
        $this->assertSame('tos_forbidden', N8nZoomExecutionProbe::classify('403 Terms of Service'));
        $this->assertSame('other', N8nZoomExecutionProbe::classify('node timeout'));
    }

    public function test_live_webhook_token_is_reported_as_credential_failure_evidence(): void
    {
        $this->fakeN8n(
            error: 'H3952_UNDECIDABLE_3301 | verdict=undecidable_3301 | meeting=87947840623',
            downloadUrl: 'https://zoom.us/rec/webhook_download/abc?access_token=live-token',
            tokenStatus: 200,
        );

        Artisan::call('recordings:gap-watch', ['--dry' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('вебхук-токен: ЖИВ', $out);
        $this->assertStringContainsString('сбой credential/fetch', $out);
    }

    public function test_dead_webhook_token_is_reported_as_the_expired_window(): void
    {
        $this->fakeN8n(
            error: 'H3952_WEBHOOK_MISSING | verdict=webhook_missing | meeting=87947840623',
            downloadUrl: 'https://zoom.us/rec/webhook_download/abc?access_token=dead-token',
            tokenStatus: 401,
        );

        Artisan::call('recordings:gap-watch', ['--dry' => true]);
        $out = Artisan::output();

        $this->assertStringContainsString('вебхук-токен: мёртв', $out);
        $this->assertStringContainsString('записи нет', $out);
    }

    public function test_a_successful_execution_is_not_probed_for_a_token(): void
    {
        Http::fake([
            'https://context-ai.ru/api/v1/executions*' => Http::response([
                'data' => [['id' => '2222', 'status' => 'success', 'startedAt' => '2026-09-02T06:53:34Z']],
            ]),
        ]);

        Artisan::call('recordings:gap-watch', ['--dry' => true]);

        $this->assertStringNotContainsString('вебхук-токен', Artisan::output());
        Http::assertNotSent(fn ($r) => $r->method() === 'HEAD');
    }

    private function fakeN8n(string $error, string $downloadUrl, int $tokenStatus): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 21:00', 'Europe/Moscow'));

        Http::fake(function ($request) use ($error, $downloadUrl, $tokenStatus) {
            if ($request->method() === 'HEAD') {
                return Http::response('', $tokenStatus);
            }
            if (str_contains($request->url(), '/api/v1/executions/')) {
                return Http::response([
                    'id' => '2314',
                    'status' => 'error',
                    'data' => ['resultData' => [
                        'error' => ['message' => $error],
                        'runData' => ['Code in JavaScript1' => [['data' => [
                            'main' => [[['json' => ['download_url' => $downloadUrl]]]],
                        ]]]],
                    ]],
                ]);
            }

            return Http::response([
                'data' => [['id' => '2314', 'status' => 'error', 'startedAt' => '2026-09-02T20:54:32Z']],
            ]);
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }
}
