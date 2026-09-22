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
trait StudentCertificateConcerns
{
/**
     * Скачивание сертификата
     */
    public function downloadCertificate($id, CertificateService $service)
    {
        $certificate = auth()->user()->certificates()->with('course')->findOrFail($id);
        $pdf = $service->generatePdf($certificate);

        return $pdf->download('Certificate_'.$certificate->course->id.'.pdf');
    }

/**
     * Скачивание сертификата картинкой (JPEG).
     */
    public function downloadCertificateImage($id, CertificateService $service)
    {
        $certificate = auth()->user()->certificates()->with('course')->findOrFail($id);

        try {
            $jpeg = $service->generateJpegBytes($certificate);
        } catch (\RuntimeException|\ImagickException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response()->streamDownload(
            fn () => print $jpeg,
            'Certificate_'.$certificate->course->id.'.jpg',
            ['Content-Type' => 'image/jpeg'],
        );
    }

/**
     * === ВСПОМОГАТЕЛЬНЫЙ МЕТОД: Парсер ссылок видео ===
     * H4396: public static — RecordingGateController (серверные ворота
     * записи) резолвит тот же ID, не плодя вторую реализацию правила
     * «какая ссылка чем открывается» (прецедент H3308).
     */
    public static function parseVideoId(?string $url, string $platform): ?string
    {
        if (! $url) {
            return null;
        }

        if ($platform === 'youtube') {
            preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches);

            return $matches[1] ?? null;
        }

        if ($platform === 'rutube') {
            $parsed = parse_url($url);
            $path = $parsed['path'] ?? '';
            $query = $parsed['query'] ?? '';

            if (preg_match('/([a-zA-Z0-9]{32})/', $path, $matches)) {
                $cleanId = $matches[1];
                if (! empty($query)) {
                    return $cleanId.'?'.$query;
                }

                return $cleanId;
            }
        }

        return null;
    }
}
