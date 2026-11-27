<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Mail\CabinetEmailVerificationMail;
use App\Models\MagicLinkToken;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/** Мягкое подтверждение почты для кабинетов, созданных через /кабинет. */
final class CabinetEmailVerification
{
    public const PURPOSE = 'cabinet_verify';

    private const TTL_MINUTES = 7 * 24 * 60;

    public function enabled(): bool
    {
        return (bool) config('features.cabinet_email_verification', false);
    }

    /** Одноразовая ссылка уходит ТОЛЬКО на почту — владение ящиком и есть доказательство. */
    public function send(User $user): void
    {
        if (! $this->enabled()) {
            return;
        }

        $token = MagicLinkToken::issueFor($user, self::PURPOSE, self::TTL_MINUTES);

        Mail::to($user->email)->queue(new CabinetEmailVerificationMail(
            $user->greetingName(),
            route('cabinet.verify-email', $token),
        ));
    }
}
