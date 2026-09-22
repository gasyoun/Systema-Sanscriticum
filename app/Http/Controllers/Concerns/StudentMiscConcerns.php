<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\TrackLessonViewJob;
use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\CourseFavorite;
use App\Models\CourseWaitlistItem;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\LessonView;
use App\Models\Payment;
use App\Models\PranaPerk;
use App\Models\PranaRedemption;
use App\Models\Schedule;
use App\Models\SubscriberMagnet;
use App\Models\User;
use App\Models\WaitlistVote;
use App\Services\AccessDiagnosticsService;
use App\Services\Activity\CabinetTelemetry;
use App\Services\Activity\FunnelTelemetry;
use App\Services\Cabinet\GrammarLadder;
use App\Services\Cabinet\RecordingsCatalog;
use App\Services\Cabinet\RecoveryState;
use App\Services\Cabinet\RecoveryStateResolver;
use App\Services\CourseContinuationBanner;
use App\Services\DebtPaymentResolver;
use App\Services\HindiAttachmentDrills;
use App\Services\HindiProgrammePlaylist;
use App\Services\HindiTranscriptDrills;
use App\Services\Leaderboard\LeaderboardService;
use App\Services\Learning\ExternalLearningProgressService;
use App\Services\Membership\ClubEntitlement;
use App\Services\Membership\ClubMembershipService;
use App\Services\Membership\RecordingAccessPolicy;
use App\Services\Prana\PranaService;
use App\Services\Prana\PranaSettings;
use App\Services\Schedule\TextbookScale;
use App\Services\StudentDebtsService;
use App\Support\Badges;
use App\Support\KinescopePilot;
use App\Support\OnboardingChecklist;
use App\Support\PranaLeaderboard;
use App\Support\TranscriptParser;
use App\Support\VisualDcsEntitlement;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Domain-scoped concern for StudentController — extracted by H4978 split
 * (recovered under H5245 PR-sweep). Implementations are main's current bodies.
 */
trait StudentMiscConcerns
{
/**
     * Раздел «Открытые уроки / вебинары» — доступен любому залогиненному студенту.
     * Показывает все уроки с is_free=true (независимо от покупок и групп).
     */
    public function openLessons()
    {
        $lessons = Lesson::free()
            ->where('is_published', true)
            ->with('course:id,title,slug')
            ->orderByDesc('lesson_date')
            ->orderByDesc('id')
            ->get();

        return view('student.open-lessons', compact('lessons'));
    }

/**
     * H1680 — Wave 2: cabinet skill-drill strip. Links out to the existing
     * free /lila drills, DISTINCT from the FSRS review loop at /dvaram/koloda —
     * short single-item practice, no spaced-repetition scheduling here.
     * Static curated list (the drills themselves live in public/lila/, not
     * in the DB) — matches the "not FSRS" scope of this handoff.
     */
    public function skillDrills()
    {
        $drills = [
            ['family' => 'sort', 'label' => 'Гласные: долгие и краткие', 'url' => '/lila/sort/vowel-length/'],
            ['family' => 'match', 'label' => 'IAST ↔ кириллица', 'url' => '/lila/match/iast-cyrillic/'],
            ['family' => 'match', 'label' => 'Кочергина, урок 1', 'url' => '/lila/match/kochergina-l1/'],
            ['family' => 'roots', 'label' => 'Корни: топ-25', 'url' => '/lila/roots/top-25/'],
            ['family' => 'ligatures', 'label' => 'Лигатуры: топ-10', 'url' => '/lila/ligatures/top-10/'],
            ['family' => 'cloze', 'label' => 'Ранг корня: клоуз', 'url' => '/lila/cloze/root-rank/'],
        ];

        return view('student.skill-drills', compact('drills'));
    }

    public function messages()
    {
        $user = auth()->user();

        // Добавили круглые скобки () и явно указали таблицу, чтобы избежать конфликтов!
        $userGroupIds = $user->groups()->pluck('groups.id')->toArray();

        $messages = Announcement::where('is_published', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(function ($announcement) use ($userGroupIds) {
                if (empty($announcement->target_groups)) {
                    return true;
                }

                return count(array_intersect($announcement->target_groups, $userGroupIds)) > 0;
            });

        return view('student.messages', compact('messages'));
    }
}
