<?php

declare(strict_types=1);

namespace App\Support\Telephony;

use InvalidArgumentException;

/**
 * H2486 — immutable call-event DTO for the customer timeline adapter.
 *
 * Not persisted. Phase 1+ may write the same allow-listed types onto
 * ActivityEvent (preferred) or a narrow call_events channel table.
 * Audio bytes never belong on this object.
 *
 * @see docs/PACKET_JIVO_TELEPHONY_PROVIDER_ROUTING_GATE_2026.md
 */
final class CallEvent
{
    public const REQUESTED = 'call.requested';

    public const STARTED = 'call.started';

    public const ANSWERED = 'call.answered';

    public const MISSED = 'call.missed';

    public const ENDED = 'call.ended';

    public const RECORDING_AVAILABLE = 'call.recording_available';

    public function __construct(
        public readonly string $type,
        public readonly ?int $userId,
        public readonly ?int $supportConversationId,
        public readonly ?int $followUpTaskId,
        public readonly ?string $providerCallId,
        public readonly ?int $durationSeconds,
        public readonly bool $recordingMetadataOnly = true,
    ) {
        $allowed = config('telephony.event_types', [
            self::REQUESTED,
            self::STARTED,
            self::ANSWERED,
            self::MISSED,
            self::ENDED,
            self::RECORDING_AVAILABLE,
        ]);
        if (! in_array($type, $allowed, true)) {
            throw new InvalidArgumentException("Unknown call event type [{$type}].");
        }
    }

    public static function requested(?int $userId, ?int $conversationId, ?int $followUpTaskId): self
    {
        return new self(self::REQUESTED, $userId, $conversationId, $followUpTaskId, null, null);
    }
}
