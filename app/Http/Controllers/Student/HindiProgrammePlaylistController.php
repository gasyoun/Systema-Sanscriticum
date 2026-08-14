<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\HindiProgrammePlaylist;
use App\Services\HindiTranscriptDrills;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * H2441 — classic-cabinet Hindi programme playlist.
 *
 * Independent of CABINET_HYBRID. Flag OFF → 404.
 */
class HindiProgrammePlaylistController extends Controller
{
    public function hindi(Request $request, HindiProgrammePlaylist $playlist, HindiTranscriptDrills $drills): View
    {
        abort_unless($playlist->enabled(), 404);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $items = $playlist->itemsFor($user);
        if ($drills->enabled()) {
            $items = $items->map(function (array $row) use ($drills): array {
                $lesson = $row['lesson'];
                $has = $drills->hasItems($lesson);
                $row['has_drills'] = $has;
                $row['drills_url'] = $has
                    ? route('student.lesson.drills', [$row['course']->slug, $lesson->id])
                    : null;

                return $row;
            });
        }

        return view('student.programme.hindi', [
            'items' => $items,
            'count' => $items->count(),
        ]);
    }
}
