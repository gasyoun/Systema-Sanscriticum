<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GrammarLabPilotParticipant;
use App\Services\GrammarLab\GrammarLabPilot;
use App\Support\GrammarLabAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * H2495 — consented Grammar Lab learner pilot. Flag OFF → 404.
 * Does not charge, grant production access, or export identity.
 */
class GrammarLabPilotController extends Controller
{
    private const CONSENT_TEXT = <<<'TEXT'
        Это пилот Лаборатории грамматики Общества ревнителей санскрита. Мы просим вас найти одну тему (чередование корня), сравнить Уитни и Зализняка, ответить на короткое упражнение и при желании отметить, что было непонятно. Участие добровольное, результаты используются только обезличенно (сколько человек справились, сколько ответов верны — без имён и оценок). В любой момент можно выйти. Плата за участие не берётся, подписка не оформляется.
        TEXT;

    public function __construct(private readonly GrammarLabPilot $pilot) {}

    public function intro(Request $request): View|Response
    {
        $this->guard();
        $user = $request->user();
        abort_unless($user !== null, 403);

        return view('grammar-lab.pilot-intro', [
            'participant' => $this->pilot->participantFor($user),
            'eligible' => $this->pilot->isEligible($user),
            'consentText' => self::CONSENT_TEXT,
        ]);
    }

    public function enroll(Request $request): RedirectResponse|Response
    {
        $this->guard();
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless($this->pilot->isEligible($user), 403, 'Пилот только для текущих студентов промежуточного уровня.');

        $data = $request->validate([
            'prior_exposure' => 'required|in:'.implode(',', GrammarLabPilotParticipant::EXPOSURE_LEVELS),
            'consent' => 'required|accepted',
        ]);

        $this->pilot->enroll($user, $data['prior_exposure']);

        return redirect()->route('student.grammar-lab.index');
    }

    public function confusion(Request $request): RedirectResponse|Response
    {
        $this->guard();
        $user = $request->user();
        abort_unless($user !== null && GrammarLabAccess::canUse($user), 403);

        $data = $request->validate([
            'code' => 'required|in:'.implode(',', GrammarLabPilot::CONFUSION_CODES),
            'topic_id' => 'nullable|string|max:160',
            'note' => 'nullable|string|max:500',
        ]);

        $this->pilot->record($user, GrammarLabPilot::EVENT_CONFUSION, [
            'code' => $data['code'],
            'topic_id' => $data['topic_id'] ?? null,
            'has_note' => filled($data['note'] ?? null),
        ]);

        return back()->with('status', 'confusion_recorded');
    }

    private function guard(): void
    {
        abort_unless(GrammarLabAccess::featureOn() && $this->pilot->flagOn(), 404);
    }
}
