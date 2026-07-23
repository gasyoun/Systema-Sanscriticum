<?php

declare(strict_types=1);

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Models\CampaignRecipient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;

/**
 * H1449 B4 — the two campaign-tracking endpoints. Both resolve the
 * CampaignRecipient server-side from an opaque `pixel_token`; no user_id or
 * email ever appears in the URL (org privacy rule). Both 404 when
 * `email_campaigns` (config/features.php) is OFF — the "prod-inert" guarantee.
 */
class TrackingController extends Controller
{
    /** A 1×1 transparent GIF. */
    private const PIXEL_GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

    public function openPixel(string $token): Response
    {
        abort_unless((bool) config('features.email_campaigns'), 404);

        $recipient = CampaignRecipient::query()->where('pixel_token', $token)->first();
        $recipient?->markOpened();

        return response(self::PIXEL_GIF, 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function click(string $token, string $link): RedirectResponse
    {
        abort_unless((bool) config('features.email_campaigns'), 404);

        $recipient = CampaignRecipient::query()->where('pixel_token', $token)->first();
        abort_unless($recipient !== null, 404);

        $target = $this->decodeLink($link);
        abort_unless($target !== null, 404);

        $recipient->markClicked();

        return redirect()->away($target);
    }

    /**
     * The click-redirect target is never taken from raw user input: it's an
     * opaque token this app itself produced by encrypting the real URL at
     * render time (CampaignHtmlRenderer). A token that fails to decrypt is
     * not "an unlisted URL" — it isn't a URL at all, so this can never become
     * an open redirect.
     */
    public static function encodeLink(string $url): string
    {
        $encrypted = Crypt::encryptString($url);

        return rtrim(strtr(base64_encode($encrypted), '+/', '-_'), '=');
    }

    private function decodeLink(string $token): ?string
    {
        $padded = str_pad(strtr($token, '-_', '+/'), (int) ceil(strlen($token) / 4) * 4, '=');

        try {
            $encrypted = base64_decode($padded, true);
            if ($encrypted === false) {
                return null;
            }

            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }
}
