<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TeachingGlossaryCourse;
use App\Models\TeachingGlossaryTerm;
use App\Services\Membership\ClubEntitlement;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Поверхность тира Top (5 000 ₽/мес) — преподавательский глоссарий (H4832).
 *
 * Гейт — двухключевой, по дому:
 *  1) features.teaching_glossary (TEACHING_GLOSSARY, default OFF → 404):
 *     поверхность не существует, пока MG её не включит;
 *  2) ClubEntitlement::allows(user, 'teaching_glossary') — capability
 *     `teaching_glossary` в config/membership.php стоит на тире `top`,
 *     поэтому мимо проходят только действующие Top-члены (ранг 30).
 * Не-член и Basic-клубник получают тот же 404, что и гость страницы: состав
 * закрытых поверхностей не раскрываем.
 */
final class TeachingGlossaryController extends Controller
{
    public function __construct(private readonly ClubEntitlement $entitlement) {}

    public function index(Request $request): View
    {
        abort_unless((bool) config('features.teaching_glossary', false), 404);

        $user = $request->user();

        if (! $this->entitlement->allows($user, 'teaching_glossary')) {
            abort(404);
        }

        $query = trim((string) $request->query('q', ''));
        $inCourses = trim((string) $request->query('course', ''));

        $terms = TeachingGlossaryTerm::query()
            ->when($query !== '', function ($builder) use ($query): void {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $query).'%';
                $builder->where(function ($inner) use ($like): void {
                    $inner->where('cyrillic_form', 'like', $like)
                        ->orWhere('ru_gloss', 'like', $like)
                        ->orWhere('lemma_slp1', 'like', $like);
                });
            })
            ->orderByDesc('corpus_freq')
            ->limit(1500)
            ->get();

        $courses = TeachingGlossaryCourse::query()->orderByDesc('n_headwords')->get();

        return view('teaching-glossary.index', [
            'terms' => $terms,
            'courses' => $courses,
            'query' => $query,
            'activeCourse' => $inCourses,
            'stats' => [
                'total' => TeachingGlossaryTerm::query()->count(),
                'courses' => TeachingGlossaryCourse::query()->count(),
            ],
        ]);
    }
}
