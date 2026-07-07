<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Livewire\SrsReview;
use Illuminate\View\View;

/**
 * SRS-карточки (H211, Wave 1). Тонкая обёртка: страница личного кабинета,
 * встраивающая Livewire-компонент {@see SrsReview}. Доступна только
 * при включённом флаге srs.enabled (маршрут регистрируется под тем же условием).
 */
class SrsController extends Controller
{
    public function review(): View
    {
        abort_unless((bool) config('srs.enabled'), 404);

        return view('student.srs');
    }
}
