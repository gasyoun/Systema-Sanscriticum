<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Rq4Participant;
use App\Models\Rq4Response;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * H987 -- RQ4 user study (SanskritGrammar docs/RQ4_EVALUATION_PROTOCOL_2026.md):
 * on-ramp-first vs Талмуд-first learning-gain/retention study.
 *
 * Flow: intro+consent -> intake (self-reported prior exposure) + stratified
 * arm assignment -> pre_test diagnostic -> arm content (external link, on-ramp
 * or Талмуд) -> post_test diagnostic -> (+4 weeks, reminder via the existing
 * ScheduledReminder infra) -> retention_test diagnostic.
 *
 * Gated by config('features.rq4_study'); OFF by default -- the whole
 * controller 404s exactly like /slovar did before its own Wave 0 (H204).
 */
class Rq4StudyController extends Controller
{
    private const ITEM_BANK_PATH = 'data/rq4_item_bank.json';

    private function guardFlag(): void
    {
        abort_if(! config('features.rq4_study', false), 404);
    }

    /** Consent text (protocol Sec6.4) — approved by MG 15-07-2026. */
    private const CONSENT_TEXT = <<<'TEXT'
        Это исследование — часть работы Общества ревнителей санскрита над тем, как лучше учить санскриту. Вам будет показан один из двух вариантов введения в тему рядов/типов корней, затем короткий тест (несколько вопросов), и ещё раз — через 4 недели, без дополнительных материалов между тестами. Участие добровольное, результаты используются обезличенно (для анализа, не для оценки успеваемости). В любой момент можно выйти без объяснения причин.
        TEXT;

    public function intro(): View
    {
        $this->guardFlag();

        $existing = Rq4Participant::where('user_id', auth()->id())->first();

        return view('rq4.intro', ['participant' => $existing, 'consentText' => self::CONSENT_TEXT]);
    }

    public function enroll(Request $request): RedirectResponse
    {
        $this->guardFlag();

        $data = $request->validate([
            'prior_exposure' => 'required|in:'.implode(',', Rq4Participant::EXPOSURE_LEVELS),
            'consent' => 'required|accepted',
        ]);

        $userId = auth()->id();

        $participant = Rq4Participant::firstOrCreate(
            ['user_id' => $userId],
            [
                'prior_exposure' => $data['prior_exposure'],
                'arm' => Rq4Participant::assignArm($data['prior_exposure'], $userId),
                'consented_at' => now(),
            ],
        );

        return redirect()->route('rq4.diagnostic', ['phase' => Rq4Response::PHASE_PRE_TEST]);
    }

    /** Shows the next unanswered item in the phase, or the phase-complete transition. */
    public function diagnostic(string $phase): View|RedirectResponse
    {
        $this->guardFlag();
        $this->assertValidPhase($phase);

        $participant = $this->requireParticipant();
        $items = $this->itemsForPhase($phase);

        $answeredSlp1 = $participant->responses()
            ->where('phase', $phase)
            ->pluck('item_slp1')
            ->all();

        $next = collect($items)->first(fn ($item) => ! in_array($item['slp1'], $answeredSlp1, true));

        if ($next === null) {
            return $this->completePhase($participant, $phase);
        }

        return view('rq4.diagnostic-item', [
            'phase' => $phase,
            'item' => $next,
            'answeredCount' => count($answeredSlp1),
            'totalCount' => count($items),
        ]);
    }

    public function submitAnswer(Request $request, string $phase): RedirectResponse
    {
        $this->guardFlag();
        $this->assertValidPhase($phase);

        $data = $request->validate([
            'item_slp1' => 'required|string',
            'answered_ryad' => 'required|string',
            'answered_tip' => 'required|string',
            'answered_set' => 'required|string',
        ]);

        $participant = $this->requireParticipant();
        $items = $this->itemsForPhase($phase);
        $item = collect($items)->firstWhere('slp1', $data['item_slp1']);

        abort_if($item === null, 404);

        $correct = $item['ryad'] === $data['answered_ryad']
            && $item['tip'] === $data['answered_tip']
            && $item['set'] === $data['answered_set'];

        Rq4Response::firstOrCreate(
            ['participant_id' => $participant->id, 'phase' => $phase, 'item_slp1' => $data['item_slp1']],
            [
                'answered_ryad' => $data['answered_ryad'],
                'answered_tip' => $data['answered_tip'],
                'answered_set' => $data['answered_set'],
                'correct' => $correct,
                'answered_at' => now(),
            ],
        );

        return redirect()->route('rq4.diagnostic', ['phase' => $phase]);
    }

    /** Learner clicks through to the arm's material (on-ramp or Talmud) after pre_test. */
    public function startArm(): RedirectResponse
    {
        $this->guardFlag();

        $participant = $this->requireParticipant();

        if ($participant->arm_content_started_at === null) {
            $participant->update(['arm_content_started_at' => now()]);
        }

        $url = $participant->arm === Rq4Participant::ARM_ON_RAMP
            ? 'https://gasyoun.github.io/SanskritGrammar/docs/onramp' // on-ramp-first
            : 'https://gasyoun.github.io/SanskritGrammar/docs/talmud-00-vvedenie'; // talmud-first

        return redirect()->away($url);
    }

    private function completePhase(Rq4Participant $participant, string $phase): View
    {
        return match ($phase) {
            Rq4Response::PHASE_PRE_TEST => $this->completePreTest($participant),
            Rq4Response::PHASE_POST_TEST => $this->completePostTest($participant),
            Rq4Response::PHASE_RETENTION_TEST => view('rq4.complete-retention'),
        };
    }

    private function completePreTest(Rq4Participant $participant): View
    {
        if ($participant->pre_test_completed_at === null) {
            $participant->update(['pre_test_completed_at' => now()]);
        }

        return view('rq4.complete-pre-test', ['arm' => $participant->arm]);
    }

    private function completePostTest(Rq4Participant $participant): View
    {
        if ($participant->post_test_completed_at === null) {
            $participant->update([
                'post_test_completed_at' => now(),
                'retention_due_at' => now()->addDays(Rq4Participant::RETENTION_WINDOW_DAYS),
            ]);
        }

        return view('rq4.complete-post-test');
    }

    private function requireParticipant(): Rq4Participant
    {
        $participant = Rq4Participant::where('user_id', auth()->id())->first();
        abort_if($participant === null, 404, 'Not enrolled in the RQ4 study.');

        return $participant;
    }

    private function assertValidPhase(string $phase): void
    {
        abort_unless(
            in_array($phase, [Rq4Response::PHASE_PRE_TEST, Rq4Response::PHASE_POST_TEST, Rq4Response::PHASE_RETENTION_TEST], true),
            404,
        );
    }

    /** @return list<array{slp1:string,root:string,ryad:string,tip:string,set:string}> */
    private function itemsForPhase(string $phase): array
    {
        $bank = $this->readItemBank();

        return $bank['sets'][$phase] ?? [];
    }

    /** @return array{sets: array<string, list<array<string,mixed>>>} */
    private function readItemBank(): array
    {
        $path = resource_path(self::ITEM_BANK_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Item bank not found at {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['sets'])) {
            throw new RuntimeException("Invalid or malformed item bank at {$path}");
        }

        return $data;
    }
}
