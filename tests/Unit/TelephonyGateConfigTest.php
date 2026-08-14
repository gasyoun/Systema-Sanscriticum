<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Telephony\CallEvent;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * H2486 — pin telephony / department / routing flags OFF and lock the
 * allow-listed CallEvent types. Do not config()->set the flags here.
 */
class TelephonyGateConfigTest extends TestCase
{
    public function test_all_telephony_flags_default_off(): void
    {
        $this->assertFalse((bool) config('features.telephony_callback_request'));
        $this->assertFalse((bool) config('features.telephony_pstn'));
        $this->assertFalse((bool) config('features.telephony_recording'));
        $this->assertFalse((bool) config('features.support_departments'));
        $this->assertFalse((bool) config('features.support_capacity_routing'));
    }

    public function test_preferred_carrier_is_none_and_jivo_aon_is_forbidden(): void
    {
        $this->assertSame('none', config('telephony.preferred_carrier'));
        $this->assertSame('+74993504327', config('telephony.forbidden_default_aon'));
        $this->assertFalse((bool) config('telephony.recording.store_audio_in_app'));
        $this->assertFalse((bool) config('telephony.recording.send_transcript_to_llm'));
    }

    public function test_call_event_rejects_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CallEvent('call.invented', null, null, null, null, null);
    }

    public function test_requested_factory_uses_allow_listed_type(): void
    {
        $event = CallEvent::requested(1, 2, 3);
        $this->assertSame(CallEvent::REQUESTED, $event->type);
        $this->assertContains($event->type, config('telephony.event_types'));
        $this->assertTrue($event->recordingMetadataOnly);
    }
}
