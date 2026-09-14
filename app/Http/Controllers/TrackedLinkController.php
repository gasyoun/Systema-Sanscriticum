<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class TrackedLinkController extends Controller
{
    /** Store campaign attribution without exposing it in the destination URL. */
    public function __invoke(Request $request, string $link): RedirectResponse
    {
        $definition = config("tracked_links.links.{$link}");

        abort_unless(is_array($definition), 404);

        $sessionKey = (string) config('tracked_links.session_key');
        if (! $request->session()->has($sessionKey)) {
            $request->session()->put($sessionKey, $definition['utm']);
        }

        return redirect()->to($definition['destination']);
    }
}
