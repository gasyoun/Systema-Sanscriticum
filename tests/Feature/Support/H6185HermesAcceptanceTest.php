<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Services\Support\Faq\Bm25FaqRetriever;
use App\Services\Support\Faq\FaqChunk;
use App\Services\Support\Faq\FaqCorpusParser;
use App\Services\Support\Faq\FaqScoreFloor;
use App\Services\Support\Hermes\FaqRag\W1FaqRagAnswerComposer;
use App\Services\Support\Hermes\FaqRag\W1FaqRagCitationVerifier;
use App\Services\Support\Hermes\FaqRag\W1FaqRagGoldenScenarioRunner;
use App\Services\Support\Hermes\FaqRag\W1FaqRagResult;
use App\Services\Support\Hermes\GoldenCaseEvaluator;
use App\Services\Support\SupportStudentPiiMasker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRecordingFaqRagLlm;
use Tests\TestCase;

/**
 * H6185 acceptance (hermes-24-01a10bca): W1 FAQ-RAG роль 20 — ответы с
 * проверяемыми цитатами. Обёртка над GoldenCaseEvaluator H6181 (role_20_faq_rag.json
 * против живого W1FaqRagAnswerComposer) плюс новые тесты: цитаты разрешимы против
 * утверждённого FAQ-корпуса (missing/stale/contradictory → отказ или человеку),
 * PII до LLM (H6182, fail-closed), score floor H3766 B5 без понижения планки
 * пилота, роль OFF по умолчанию (W1 deploy gate), mock LLM path, без исходящих
 * отправок. Фикстуры синтетические (provenance H6181); прод-проб нет.
 */
class H6185HermesAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private const QUESTION_FAQ = 'Какие учебники нужны для занятий на курсе новичка?';

    protected function setUp(): void
    {
        parent::setUp();

        // Роль 20 (H6185) — flag ON только в тестовом контексте; в проде
        // флаг ВЫКЛ (role 20 stays OFF, W1 deploy gate).
        config(['features.hermes_faq_rag' => true]);
        // Роль 23 исполняется в общем golden-прогоне (как в H6181/H6184/H6190).
        config(['features.hermes_schedule_keeper' => true]);
    }

    /**
     * Обёртка над существующим валидатором: golden-набор H6181 валиден,
     * роль 20 исполняется живьём (4/4), роль 23 не сломана, правило
     * «ошибки в знаменателе» живо.
     */
    public function test_wrapper_existing_golden_validator_stays_green(): void
    {
        $evaluator = $this->app->make(GoldenCaseEvaluator::class);

        // Регистрируем раннер роли 20 — вызывает faq-rag композер.
        $evaluator->registerRunner(20, new W1FaqRagGoldenScenarioRunner);

        $report = $evaluator->evaluate();

        $this->assertSame('hermes-w1-golden', $report['set']);
        $this->assertSame(1, $report['set_version']);
        $this->assertSame('H6181', $report['handoff']);

        $role20 = $report['roles']['20'];
        $this->assertSame('implemented', $role20['implementation_status'], 'role 20: implementation landed');
        $this->assertSame('evaluated', $role20['status'], 'role 20: implemented and evaluated');
        $this->assertSame(4, $role20['executed'], 'role 20: 4 golden cases executed');
        $this->assertSame(4, $role20['passed'], 'role 20: all cases pass');
        $this->assertSame(0, $role20['errors'], 'role 20: no errors');
        $this->assertSame(0, $role20['failed'], 'role 20: no failures');

        // Роль 23 остаётся зелёной (не сломана).
        $this->assertSame(4, $report['roles']['23']['executed']);
        $this->assertSame(4, $report['roles']['23']['passed']);
        $this->assertSame(0, $report['roles']['23']['errors']);

        $this->assertTrue($report['score']['errors_in_denominator']);
        $this->assertNotContains(20, $report['inconclusive'], 'role 20: реализована — не INCONCLUSIVE');
    }

    /**
     * Golden-фикстура fq-001..fq-004: каждый версионированный ключ expected
     * сверяется с наблюдением (H6190-паттерн); drafted-ответ обязан нести
     * проверяемые цитаты (must_cite).
     */
    public function test_golden_cases_match_all_versioned_expected_outcomes(): void
    {
        $fixture = json_decode((string) file_get_contents(
            base_path(GoldenCaseEvaluator::FIXTURE_DIR.'/role_20_faq_rag.json'),
        ), true);

        $this->assertTrue($fixture['provenance']['synthetic'], 'role 20 fixture: synthetic provenance');
        $this->assertFalse($fixture['provenance']['student_dialogues_copied'], 'role 20 fixture: no copied dialogues');
        $this->assertSame('implemented', $fixture['implementation_status'], 'role 20: implementation landed');

        $composer = new W1FaqRagAnswerComposer;
        $before = $this->canonicalSnapshot();
        $checked = 0;
        $casesSeen = 0;

        foreach ($fixture['cases'] as $case) {
            $casesSeen++;
            $result = $composer->compose(
                (string) $case['input']['message'],
                (array) ($case['input']['context'] ?? []),
            );
            $observation = $result->toObservation();
            $expected = $case['expected'];

            $this->assertSame(
                $expected['outcome'],
                $observation['outcome'],
                "{$case['id']}: outcome (".json_encode($expected, JSON_UNESCAPED_UNICODE).')',
            );
            $this->assertContains($result->outcome, W1FaqRagResult::OUTCOMES, "{$case['id']}: canonical outcome");

            foreach (['route_to_human', 'human_send_required', 'no_confident_guess', 'delegate_hint', 'forbidden_contour'] as $key) {
                if (array_key_exists($key, $expected)) {
                    $this->assertSame($expected[$key], $observation[$key], "{$case['id']}: {$key}");
                    $checked++;
                }
            }

            if (($expected['must_cite'] ?? false) === true) {
                $this->assertTrue($observation['citations_verified'], "{$case['id']}: must_cite — цитаты проверены");
                $this->assertStringContainsString('[cite:', (string) $observation['draft'], "{$case['id']}: must_cite — маркер в черновике");
                $checked++;
            }

            // Инвариант миссии: КАЖДЫЙ ответ несёт проверяемые цитаты.
            if ($result->outcome === W1FaqRagResult::OUTCOME_DRAFTED) {
                $this->assertTrue($result->citationsVerified(), "{$case['id']}: drafted без проверенных цитат недопустим");
            }

            $checked++;
        }

        $this->assertSame(4, $casesSeen, 'четыре golden-кейса сверены');
        $this->assertGreaterThanOrEqual(4, $checked, 'версионированные ключи сверены');
        $this->assertSame($before, $this->canonicalSnapshot(), 'role 20: no side effects — canonical-таблицы не менялись');
    }

    /**
     * Happy-путь: маркеры [cite:chunk_id] разрешимы против утверждённого
     * корпуса (resources/knowledge/faq.md — источник citation-key drift gate).
     */
    public function test_drafted_answer_citations_resolve_against_approved_corpus(): void
    {
        $result = (new W1FaqRagAnswerComposer)->compose(self::QUESTION_FAQ, ['channel' => 'tg', 'author' => 'student']);

        $this->assertSame(W1FaqRagResult::OUTCOME_DRAFTED, $result->outcome);
        $this->assertSame('faq_rag', $result->source);
        $this->assertTrue($result->humanSendRequired, 'черновик уходит в human-send (H6183), роль не отправляет');
        $this->assertFalse($result->routeToHuman);
        $this->assertGreaterThanOrEqual(0.55, (float) $result->confidence);
        $this->assertLessThanOrEqual(0.92, (float) $result->confidence);
        $this->assertTrue($result->llmCalled);

        $corpusIds = $this->corpusIds();
        $this->assertNotEmpty($corpusIds);

        $this->assertStringContainsString('[cite:', (string) $result->draft);
        preg_match_all('/\[cite:([^\]\s]+)\]/u', (string) $result->draft, $markers);
        $this->assertNotEmpty($markers[1], 'черновик обязан нести хотя бы один маркер цитаты');

        foreach ($markers[1] as $markerKey) {
            $this->assertContains((string) $markerKey, $corpusIds, "маркер {$markerKey} не разрешим в корпусе");
        }

        foreach ($result->citations as $citation) {
            $this->assertContains($citation['chunk_id'], $corpusIds, "ключ цитаты {$citation['chunk_id']} не разрешим в корпусе");
            $this->assertNotSame('', trim((string) $citation['snippet']), 'цитата без подтверждения недопустима');
        }

        // Верификатор подтверждает записи на живом корпусе (тот же путь, что в compose()).
        $report = (new W1FaqRagCitationVerifier)->verify($result->citations);
        $this->assertSame([], $report['failed'], 'повторная верификация на корпусе не даёт отказов');
        $this->assertCount(count($result->citations), $report['verified']);
    }

    /**
     * Negative-контроли верификации: missing / stale / contradictory → отказ
     * или человеку; черновик не выпускается.
     */
    public function test_missing_stale_contradictory_citations_refuse_and_route_to_human(): void
    {
        // missing: маркер с ключом, которого нет в корпусе.
        $missingLlm = new FakeRecordingFaqRagLlm('Ответ по базе знаний: выдумка. [cite:net-takogo-razdela]');
        $missing = (new W1FaqRagAnswerComposer(llm: $missingLlm))->compose(self::QUESTION_FAQ);
        $this->assertSame(W1FaqRagResult::OUTCOME_REFUSED, $missing->outcome);
        $this->assertTrue($missing->routeToHuman, 'missing-цитата → человеку');
        $this->assertNull($missing->draft, 'непроверенный ответ не выпускается');
        $this->assertSame('citation_unverified', $missing->refusalReason);
        $this->assertSame(
            [['chunk_id' => 'net-takogo-razdela', 'problem' => W1FaqRagCitationVerifier::PROBLEM_MISSING]],
            $missing->failedCitations,
        );

        // stale: маркер на раздел вне top-K — подтверждения нет (ключ корпуса
        // жив, но подтверждающий текст отсутствует).
        $topKeys = [];
        foreach ((new Bm25FaqRetriever(new FaqCorpusParser))->retrieveChunks(self::QUESTION_FAQ, (int) config('support.faq_rag.answer_top_k', 6)) as $scored) {
            $topKeys[] = $scored['chunk']->chunkId;
        }
        $farKeys = array_values(array_diff($this->corpusIds(), $topKeys));
        $this->assertNotEmpty($farKeys, 'корпус обязан содержать раздел вне top-K вопроса');
        $farKey = $farKeys[0];

        $staleLlm = new FakeRecordingFaqRagLlm('Ответ по базе знаний: не отсюда. [cite:'.$farKey.']');
        $stale = (new W1FaqRagAnswerComposer(llm: $staleLlm))->compose(self::QUESTION_FAQ);
        $this->assertSame(W1FaqRagResult::OUTCOME_REFUSED, $stale->outcome);
        $this->assertTrue($stale->routeToHuman, 'stale-цитата → человеку');
        $this->assertSame('citation_unverified', $stale->refusalReason);
        $this->assertSame(W1FaqRagCitationVerifier::PROBLEM_STALE, $stale->failedCitations[0]['problem'] ?? null);

        // contradictory: подтверждающий текст живёт в ДРУГОМ разделе.
        $chunks = (new FaqCorpusParser)->chunks();
        $this->assertGreaterThanOrEqual(2, count($chunks));
        [$chunkA, $chunkB] = [$chunks[0], $chunks[1]];
        $report = (new W1FaqRagCitationVerifier)->verify([
            ['chunk_id' => $chunkA->chunkId, 'snippet' => mb_substr($chunkB->body, 0, 80)],
        ]);
        $this->assertSame([], $report['verified']);
        $this->assertSame(W1FaqRagCitationVerifier::PROBLEM_CONTRADICTORY, $report['failed'][0]['problem']);

        // stale на уровне верификатора: сниппет исчез из корпуса (re-export).
        $report = (new W1FaqRagCitationVerifier)->verify([
            ['chunk_id' => $chunkA->chunkId, 'snippet' => 'абракадабра, которой в корпусе нет и не было'],
        ]);
        $this->assertSame(W1FaqRagCitationVerifier::PROBLEM_STALE, $report['failed'][0]['problem']);

        // missing на уровне верификатора.
        $report = (new W1FaqRagCitationVerifier)->verify([
            ['chunk_id' => 'takzhe-net-klucha', 'snippet' => 'любой текст'],
        ]);
        $this->assertSame(W1FaqRagCitationVerifier::PROBLEM_MISSING, $report['failed'][0]['problem']);
    }

    /**
     * PII-граница H6182: в LLM-вызов уходит ТОЛЬКО замаскированный текст;
     * сбой маскера = вызов отменён полностью (fail-closed).
     */
    public function test_pii_never_reaches_llm_and_masker_failure_cancels_call(): void
    {
        $capturer = new FakeRecordingFaqRagLlm;

        $result = (new W1FaqRagAnswerComposer(llm: $capturer))->compose(
            'Меня зовут Мария Ковалёва, мой телефон +7 (916) 123-45-67 и почта masha@example.com. '.self::QUESTION_FAQ,
        );

        $this->assertTrue($result->llmCalled);
        $this->assertGreaterThan(0, $result->piiMaskCount, 'PII-подстановки зафиксированы');
        $this->assertCount(1, $capturer->calls);

        $payload = json_encode($capturer->calls, JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('<PII:', $payload, 'в payload нет ни одного тега-псевдонима');
        $this->assertStringNotContainsString('+7 (916) 123-45-67', $payload, 'телефон ушёл в LLM сырым');
        $this->assertStringNotContainsString('masha@example.com', $payload, 'e-mail ушёл в LLM сырым');
        $this->assertStringNotContainsString('Мария', $payload, 'имя ушло в LLM сырым');
        $this->assertStringNotContainsString('Ковалёва', $payload, 'фамилия ушла в LLM сырым');

        // Сбой identity-сета: maskOrFail бросает — вызов отменён, текст не уходит.
        $brokenMasker = $this->createMock(SupportStudentPiiMasker::class);
        $brokenMasker->method('maskOrFail')->willThrowException(new \RuntimeException('identity source unreachable'));
        $capturer2 = new FakeRecordingFaqRagLlm;

        $failed = (new W1FaqRagAnswerComposer(piiMasker: $brokenMasker, llm: $capturer2))->compose(self::QUESTION_FAQ);

        $this->assertFalse($capturer2->called, 'сбой маскера = LLM-вызова нет (H6182 fail-closed)');
        $this->assertFalse($failed->llmCalled);
        $this->assertSame(W1FaqRagResult::OUTCOME_REFUSED, $failed->outcome);
        $this->assertSame('pii_boundary_closed', $failed->refusalReason);
        $this->assertTrue($failed->routeToHuman, 'закрытая PII-граница → человеку');
        $this->assertNull($failed->draft);
    }

    /**
     * Планка precision из пилота (H3766 B5 / K5 18-10-2026) НЕ понижается:
     * пороги те же, что в TG/web-полосах, и гейт ретривала режет ниже порога.
     */
    public function test_score_floor_pilot_bar_not_lowered_and_gates_drafting(): void
    {
        // Калибровка пилота — ровно действующие значения, без понижения.
        $this->assertSame(8.0, FaqScoreFloor::for(null));
        $this->assertSame(8.0, FaqScoreFloor::for('D'), 'категория без своего порога получает общий');
        $this->assertSame(18.1, FaqScoreFloor::for('A'));
        $this->assertSame(14.8, FaqScoreFloor::for('B'));
        $this->assertSame(19.4, FaqScoreFloor::for('C'));
        $this->assertSame(15.7, FaqScoreFloor::for('F'));

        // Ниже порога — нет уверенного источника: отказ, LLM не вызывается.
        config(['support.faq_rag.shadow_min_score' => 999.0]);
        $result = (new W1FaqRagAnswerComposer)->compose(self::QUESTION_FAQ);

        $this->assertSame(W1FaqRagResult::OUTCOME_REFUSED, $result->outcome);
        $this->assertSame('below_score_floor', $result->refusalReason);
        $this->assertFalse($result->llmCalled, 'ниже score floor черновик не собирается');
        $this->assertTrue($result->routeToHuman);
        $this->assertSame(999.0, $result->scoreFloor, 'эффективная планка зафиксирована в результате');
        $this->assertNotNull($result->bestScore);
        $this->assertLessThan($result->scoreFloor, $result->bestScore);
    }

    /**
     * Роль 20 OFF по умолчанию: без флага нет ни черновика, ни LLM-вызова —
     * W1 deploy gate stays OFF.
     */
    public function test_role_20_stays_off_without_the_deploy_flag(): void
    {
        $this->assertFalse((bool) env('HERMES_FAQ_RAG', false), 'HERMES_FAQ_RAG не включён в окружении');

        config(['features.hermes_faq_rag' => false]);
        $llm = new FakeRecordingFaqRagLlm;
        $composer = new W1FaqRagAnswerComposer(llm: $llm);

        $this->assertFalse($composer->isEnabled());

        $result = $composer->compose(self::QUESTION_FAQ);
        $this->assertSame(W1FaqRagResult::OUTCOME_REQUIRES_HUMAN, $result->outcome);
        $this->assertSame('role_disabled', $result->refusalReason);
        $this->assertFalse($llm->called, 'роль OFF: LLM-вызова нет');
        $this->assertFalse($result->llmCalled, 'роль OFF: LLM-вызова нет');
        $this->assertNull($result->draft);
        $this->assertTrue($result->routeToHuman);
    }

    /** Снапшот canonical-таблиц для no_side_effects. */
    private function canonicalSnapshot(): array
    {
        return [
            'users' => DB::table('users')->count(),
            'groups' => DB::table('groups')->count(),
            'memberships' => DB::table('group_user')->count(),
            'schedules' => DB::table('schedules')->count(),
            'payments' => DB::table('payments')->count(),
            'suggestions' => DB::table('support_answer_suggestions')->count(),
        ];
    }

    /** Ключи утверждённого FAQ-корпуса (chunk_id). */
    private function corpusIds(): array
    {
        return array_map(static fn (FaqChunk $c): string => $c->chunkId, (new FaqCorpusParser)->chunks());
    }
}
