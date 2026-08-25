<?php

namespace App\Http\Controllers;

use App\Models\Course;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Лендинг «Основы цифровой грамотности» — поток октября 2026 (лестница
 * MG 24-08-2026, Uprava docs/COURSE_DIGLIT_PRICING_RU_MARKET_24-08-2026.md).
 * Тот же принцип, что /klub (H2645): страница живёт только при включённом
 * флаге, цены приходят из тарифов курса в БД, а не из Blade.
 */
class DiglitController extends Controller
{
    public function landing(): View
    {
        abort_unless((bool) config('features.diglit_landing'), 404);

        $course = Course::query()
            ->where('slug', (string) config('diglit.course_slug'))
            ->first();
        abort_if($course === null, 404);

        $active = $course->tariffs()->where('is_active', true)->get();

        // Лестница = разовые тарифы полного формата по возрастанию цены;
        // записи-даунселл (is_recording) идёт отдельной полосой ниже.
        $ladder = $active->where('is_recording', false)->sortBy('price')->values();
        abort_if($ladder->isEmpty(), 404);
        /** @var Collection<int, \App\Models\Tariff> $recordings */
        $recordings = $active->where('is_recording', true)->sortBy('price')->values();

        return view('shop.diglit', [
            'course' => $course,
            'ladder' => $ladder,
            'recordings' => $recordings,
            'earlyBird' => $ladder->first(),
        ]);
    }
}
