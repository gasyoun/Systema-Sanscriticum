<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Mail\CampaignMail;
use App\Models\SuppressedEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * H1449 A2/W1a — end-to-end proof that App\Listeners\Email\EnforceMailSendingGuards
 * is wired into the real send pipeline. Deliberately does NOT use Mail::fake():
 * that swaps the whole mailer and never dispatches Illuminate\Mail\Events\
 * MessageSending, so it would prove nothing about this listener. Instead it
 * sends through the real (test-env) `array` transport and inspects what
 * actually reached it — the shape docs/VERIFICATION_SYSTEMA_ANTON_OPS_GAPS.md
 * calls for ("assert nothing goes out").
 */
class MailSendingGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function arrayTransport(): ArrayTransport
    {
        /** @var ArrayTransport $transport */
        $transport = app('mailer')->getSymfonyTransport();
        $transport->flush();

        return $transport;
    }

    public function test_suppressed_address_is_never_sent_to(): void
    {
        $transport = $this->arrayTransport();
        RateLimiter::clear('mail-send-throttle');

        SuppressedEmail::suppress('bounced@example.com', 'hard_bounce');

        Mail::to('bounced@example.com')->send(new CampaignMail('Subject', '<p>Body</p>'));

        $this->assertCount(0, $transport->messages());
    }

    public function test_non_suppressed_address_sends_through_the_real_transport(): void
    {
        $transport = $this->arrayTransport();
        RateLimiter::clear('mail-send-throttle');

        Mail::to('fine@example.com')->send(new CampaignMail('Subject', '<p>Body</p>'));

        $this->assertCount(1, $transport->messages());
    }
}
