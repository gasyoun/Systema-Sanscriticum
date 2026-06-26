<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PranaPerk;
use App\Services\PranaShopService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PranaShopController extends Controller
{
    /** Купить перк за прану. */
    public function redeem(Request $request, PranaPerk $perk, PranaShopService $shop): RedirectResponse
    {
        try {
            $shop->redeem($request->user(), $perk);
        } catch (\RuntimeException $e) {
            return back()->with('prana_error', $e->getMessage());
        }

        return back()->with('prana_status', "«{$perk->name}» куплено! Заявка передана администратору.");
    }
}
