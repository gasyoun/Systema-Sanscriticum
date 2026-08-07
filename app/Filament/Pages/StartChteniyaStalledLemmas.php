<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\StartChteniyaProgress;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

/**
 * H2107 — teacher view of the «Старт чтения» cohort's top stalled lemmas: words
 * students keep looking up without ever collecting into their SRS deck. Same
 * thin-page-over-a-service shape as TeacherAnalytics — the aggregation lives in
 * App\Services\StartChteniyaProgress, this page only scopes access and renders it.
 */
class StartChteniyaStalledLemmas extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 27;

    protected static ?string $navigationLabel = 'Старт чтения: застрявшие леммы';

    protected static ?string $title = 'Старт чтения: застрявшие леммы';

    protected static ?string $slug = 'start-chteniya-stalled-lemmas';

    protected static string $view = 'filament.pages.start-chteniya-stalled-lemmas';

    /** Same gate as TeacherAnalytics: any teacher, or admin-like roles for the whole cohort. */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->isTeacher() || $user?->isAdminLike());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** @return Collection<int, array{pack:string, lemma_slp1:string, surface:string, lookups:int, students:int, added:int, stalled_score:int}> */
    public function stalledLemmas(): Collection
    {
        return StartChteniyaProgress::topStalledLemmas(20);
    }
}
