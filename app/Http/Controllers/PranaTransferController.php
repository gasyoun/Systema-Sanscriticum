<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Prana\PranaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PranaTransferController extends Controller
{
    /** Подарить прану другому студенту по email. */
    public function transfer(Request $request, PranaService $prana): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'amount' => ['required', 'integer', 'min:1'],
        ]);

        $to = User::where('email', $data['email'])->first();
        if (! $to) {
            return back()->with('prana_error', 'Студент с таким email не найден.');
        }

        try {
            $prana->transfer($request->user(), $to, (int) $data['amount']);
        } catch (\RuntimeException $e) {
            return back()->with('prana_error', $e->getMessage());
        }

        return back()->with('prana_status', 'Прана отправлена студенту '.$to->name.'.');
    }
}
