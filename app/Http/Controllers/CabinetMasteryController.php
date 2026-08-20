<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CabinetMasteryAttempt;
use App\Support\CabinetMastery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * H3215 — внутренний тест кабинета для студента (/dvaram/proverka).
 */
class CabinetMasteryController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $audience = CabinetMastery::AUDIENCE_STUDENT;
        $bank = CabinetMastery::bank($audience);

        return view('student.cabinet-mastery', [
            'bank' => $bank,
            'questions' => CabinetMastery::questionsForDisplay($audience, (int) $user->id),
            'result' => null,
            'answers' => [],
        ]);
    }

    public function submit(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        $audience = CabinetMastery::AUDIENCE_STUDENT;
        $bank = CabinetMastery::bank($audience);

        $rules = [];
        foreach ($bank['questions'] as $question) {
            $rules['answers.'.$question['id']] = 'required|string';
        }
        $data = $request->validate($rules, ['required' => 'Отметьте вариант.']);

        $answers = $data['answers'];
        $graded = CabinetMastery::grade($audience, $answers);

        CabinetMasteryAttempt::create([
            'user_id' => $user->id,
            'audience' => $audience,
            'score' => $graded['score'],
            'total' => $graded['total'],
            'passed' => $graded['passed'],
            'answers' => $answers,
        ]);

        return view('student.cabinet-mastery', [
            'bank' => $bank,
            'questions' => CabinetMastery::questionsForDisplay($audience, (int) $user->id),
            'result' => $graded,
            'answers' => $answers,
        ]);
    }
}
