<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\HindiTgCuratedPractice;
use App\Services\HindiTranscriptDrillExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * H2446 — student Hindi practice from the curated TG store.
 *
 * Flag OFF → 404. No Telegram client on this path. Access = owns ≥1
 * Hindi programme lesson (H2441 unlock rules). Does not grant access.
 */
class HindiTgCuratedPracticeController extends Controller
{
    public function show(Request $request, HindiTgCuratedPractice $practice): View
    {
        abort_unless($practice->enabled(), 404);

        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless($practice->userCanAccess($user), 403);

        return view('student.programme.hindi-tg-practice', [
            'items' => $practice->itemsFor($user),
            'playlistEnabled' => (bool) config('features.hindi_programme_playlist', false),
        ]);
    }

    public function check(Request $request, HindiTgCuratedPractice $practice): JsonResponse
    {
        abort_unless($practice->enabled(), 404);

        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless($practice->userCanAccess($user), 403);

        $validated = $request->validate([
            'item_id' => ['required', 'string', 'max:80'],
            'answer' => ['required', 'string', 'max:80'],
        ]);

        $item = $practice->findItem($user, $validated['item_id']);
        abort_unless($item !== null, 404);

        $ok = HindiTranscriptDrillExtractor::answersMatch($item['answer'], $validated['answer']);

        return response()->json([
            'ok' => $ok,
            'item_id' => $item['id'],
            'correct_answer' => $ok ? null : $item['answer'],
        ]);
    }
}
