<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Http\Controllers\Email\TrackingController;
use App\Models\CampaignRecipient;

/**
 * H1449 B5 — given a Campaign's `body_html` and one recipient, rewrites every
 * `<a href>` to the click-tracking redirect and appends the open pixel. This
 * is Anton's "кнопка → персональная ссылка" made native.
 */
class CampaignHtmlRenderer
{
    public function render(string $bodyHtml, CampaignRecipient $recipient): string
    {
        $rewritten = preg_replace_callback(
            '/<a\s+([^>]*?)href=(["\'])(.*?)\2([^>]*)>/i',
            function (array $matches) use ($recipient): string {
                [, $before, $quote, $url, $after] = $matches;

                if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                    return $matches[0];
                }

                $trackedUrl = route('email.track.click', [
                    'token' => $recipient->pixel_token,
                    'link' => TrackingController::encodeLink($url),
                ]);

                return "<a {$before}href={$quote}{$trackedUrl}{$quote}{$after}>";
            },
            $bodyHtml
        ) ?? $bodyHtml;

        $pixelUrl = route('email.track.open', ['token' => $recipient->pixel_token]);

        return $rewritten.'<img src="'.$pixelUrl.'" width="1" height="1" alt="" style="display:none" />';
    }
}
