<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Class of a lesson recording (H3648). Club/Top grant only club-stream / club-efir
 * rows; course-lesson recordings stay on the ordinary purchase path.
 */
enum RecordingKind: string
{
    case CourseLesson = 'course_lesson';
    case ClubStream = 'club_stream';
    case ClubEfir = 'club_efir';

    public function isClubStream(): bool
    {
        return $this === self::ClubStream || $this === self::ClubEfir;
    }
}
