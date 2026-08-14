<?php

declare(strict_types=1);

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Services\HindiAttachmentDrills;
use App\Services\HindiProgrammePlaylist;
use App\Services\HindiTranscriptDrills;
use App\Support\HindiMySrsDeck;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * H2441 — classic-cabinet Hindi programme playlist.
 *
 * Independent of CABINET_HYBRID. Flag OFF → 404.
 */
class HindiProgrammePlaylistController extends Controller
{
    public function hindi(Request $request, HindiProgrammePlaylist $playlist, HindiTranscriptDrills $drills, HindiAttachmentDrills $attachments): View
    {
        abort_unless($playlist->enabled(), 404);

        $user = $request->user();
        abort_unless($user !== null, 403);

        $items = $playlist->itemsFor($user);
        $srsOn = HindiMySrsDeck::enabled();
        if ($drills->enabled() || $attachments->enabled() || $srsOn) {
            $items = $items->map(function (array $row) use ($drills, $attachments, $srsOn): array {
                $lesson = $row['lesson'];
                $hasTranscript = $drills->hasItems($lesson);
                $hasAttachmentPractice = $attachments->hasPracticePath($lesson);
                $hasAttachmentItems = $attachments->hasItems($lesson);
                $row['has_drills'] = ($drills->enabled() && $hasTranscript)
                    || ($attachments->enabled() && $hasAttachmentPractice);
                $row['drills_url'] = $row['has_drills']
                    ? route('student.lesson.drills', [$row['course']->slug, $lesson->id])
                    : null;
                $row['has_srs_items'] = $srsOn && ($hasTranscript || $hasAttachmentItems);

                return $row;
            });
        }

        return view('student.programme.hindi', [
            'items' => $items,
            'count' => $items->count(),
            'srsDeckEnabled' => $srsOn,
        ]);
    }
}
