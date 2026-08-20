<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\HindiDictionaryDrills;
use App\Services\HindiKostinaDictionary;
use App\Services\HindiProgrammePlaylist;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * H3206 — student practice from Kostina's module dictionaries.
 *
 * Flag OFF → 404 unless the user teaches Hindi. Access = playlist unlock.
 */
class HindiDictionaryDrillsController extends Controller
{
    public function index(
        Request $request,
        HindiDictionaryDrills $drills,
        HindiKostinaDictionary $dictionary,
        HindiProgrammePlaylist $playlist,
    ): View {
        $user = $request->user();
        abort_unless($user !== null, 403);
        $teacherPreview = $playlist->teachesHindi($user);
        abort_unless($drills->enabled() || $teacherPreview, 404);
        abort_unless($drills->userCanAccess($user), 403);

        return view('student.programme.hindi-vocab-index', [
            'modules' => $dictionary->modules(),
            'playlistEnabled' => $playlist->enabled(),
        ]);
    }

    public function show(
        Request $request,
        string $module,
        HindiDictionaryDrills $drills,
        HindiKostinaDictionary $dictionary,
        HindiProgrammePlaylist $playlist,
    ): View {
        $user = $request->user();
        abort_unless($user !== null, 403);
        $teacherPreview = $playlist->teachesHindi($user);
        abort_unless($drills->enabled() || $teacherPreview, 404);
        abort_unless($dictionary->isModule($module), 404);
        abort_unless($drills->userCanAccess($user), 403);

        return view('student.programme.hindi-vocab', [
            'module' => $module,
            'label' => $dictionary->label($module),
            'entries' => $dictionary->entriesFor($module),
            'items' => $drills->itemsFor($module),
            'playlistEnabled' => $playlist->enabled(),
        ]);
    }

    public function check(
        Request $request,
        string $module,
        HindiDictionaryDrills $drills,
        HindiKostinaDictionary $dictionary,
        HindiProgrammePlaylist $playlist,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 403);
        abort_unless($drills->enabled() || $playlist->teachesHindi($user), 404);
        abort_unless($dictionary->isModule($module), 404);
        abort_unless($drills->userCanAccess($user), 403);

        $validated = $request->validate([
            'item_id' => ['required', 'string', 'max:80'],
            'answer' => ['required', 'string', 'max:200'],
        ]);

        $item = $drills->findItem($module, $validated['item_id']);
        abort_unless($item !== null, 404);

        $ok = HindiDictionaryDrills::answersMatch($item['answer'], $validated['answer']);

        return response()->json([
            'ok' => $ok,
            'item_id' => $item['id'],
            'correct_answer' => $ok ? null : $item['answer'],
        ]);
    }
}
