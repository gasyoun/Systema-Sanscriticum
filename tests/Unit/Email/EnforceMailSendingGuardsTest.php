<?php

declare(strict_types=1);

namespace Tests\Unit\Email;

use App\Listeners\Email\EnforceMailSendingGuards;
use App\Models\SuppressedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * H1449 A2 — direct unit test of the global send guard. Mail::fake() doesn't
 * dispatch Illuminate\Mail\Events\MessageSending at all (it swaps the whole
 * mailer, bypassing the real send pipeline), so the guard can only be
 * exercised by constructing the event directly, per
 * docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md's "unit test on the throttle guard".
 */
class EnforceMailSendingGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function eventFor(string ...$recipients): MessageSending
    {
        $email = new Email;
        foreach ($recipients as $recipient) {
            $email->addTo($recipient);
        }
        $email->subject('Test')->text('Body');

        return new MessageSending($email);
    }

    public function test_suppressed_recipient_cancels_the_send(): void
    {
        SuppressedEmail::suppress('bounced@example.com', 'hard_bounce');
        RateLimiter::clear('mail-send-throttle');

        $result = (new EnforceMailSendingGuards)->handle($this->eventFor('bounced@example.com'));

        $this->assertFalse($result);
    }

    public function test_non_suppressed_recipient_is_allowed(): void
    {
        RateLimiter::clear('mail-send-throttle');

        $result = (new EnforceMailSendingGuards)->handle($this->eventFor('fine@example.com'));

        $this->assertTrue($result);
    }

    public function test_per_minute_throttle_rejects_once_exceeded(): void
    {
        config(['mail.throttle_per_minute' => 2]);
        RateLimiter::clear('mail-send-throttle');

        $guard = new EnforceMailSendingGuards;

        $this->assertTrue($guard->handle($this->eventFor('a@example.com')));
        $this->assertTrue($guard->handle($this->eventFor('b@example.com')));
        $this->assertFalse($guard->handle($this->eventFor('c@example.com')));
    }
}
