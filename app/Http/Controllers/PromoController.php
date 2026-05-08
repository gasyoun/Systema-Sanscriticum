<?php

namespace App\Http\Controllers;

use App\Models\LandingPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache; // Обязательно добавляем фасад Cache

class PromoController extends Controller
{
    public function show($slug)
    {
        // Кэшируем результат на 24 часа (86400 секунд).
        // Ключ кэша уникален для каждого лендинга, например: 'promo_page_sanskrit-bazoviy'
        $page = Cache::remember("promo_page_{$slug}", now()->addDay(), function () use ($slug) {
            return LandingPage::where('slug', $slug)
                ->where('is_active', true)
                ->firstOrFail();
        });

        $useBuilder = !empty($page->content) && is_array($page->content) && count($page->content) > 0;

        return view($useBuilder ? 'promo.show' : 'promo.legacy', compact('page'));
    }
}