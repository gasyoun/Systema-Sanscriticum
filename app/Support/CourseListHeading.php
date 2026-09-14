<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Шапка списка курсов: «N онлайн-курсов, с … по …, из них прямо сейчас
 * продолжаются занятия в M курсах».
 */
final class CourseListHeading
{
    public static function forQuery(Builder $query): string
    {
        /** @var Collection<int, Course> $courses */
        $courses = (clone $query)->get();
        $count = $courses->count();
        $noun = $count.' '.Plural::ru($count, 'онлайн-курс', 'онлайн-курса', 'онлайн-курсов');

        if ($count === 0) {
            return $noun;
        }

        $ids = $courses->pluck('id');
        $min = Lesson::query()->whereIn('course_id', $ids)->min('lesson_date');
        $max = Lesson::query()->whereIn('course_id', $ids)->max('lesson_date');

        $range = '';
        if ($min && $max) {
            $range = ', с '.Carbon::parse($min)->format('d.m.Y').' по '.Carbon::parse($max)->format('d.m.Y');
        }

        $live = collect(CourseCadence::forMany($courses))
            ->filter(fn (CourseCadence $c): bool => $c->isUnderway())
            ->count();

        if ($live === 0) {
            return $noun.$range.', из них прямо сейчас занятия не идут';
        }

        $liveNoun = Plural::ru($live, 'курсе', 'курсах', 'курсах');

        return $noun.$range.', из них прямо сейчас продолжаются занятия в '.$live.' '.$liveNoun;
    }
}
