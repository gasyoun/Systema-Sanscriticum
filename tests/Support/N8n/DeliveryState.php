<?php

declare(strict_types=1);

namespace Tests\Support\N8n;

/**
 * Per-delivery execution state of one replayed webhook delivery.
 *
 * The concurrency tests drive a delivery in three phases (lookup, create,
 * reconcile) and interleave two deliveries at phase granularity, which is
 * exactly the granularity at which shared GitHub state changes (lists are
 * reads; create/close/comment are writes).
 */
final class DeliveryState
{
    private ?SoftAlertWorkflowReplay $replay = null;

    /** @var array<string, list<array<string, mixed>>> node name => input items (walk bookkeeping) */
    public array $outputs = [];

    /** @var array<string, list<array<string, mixed>>> node name => OUTPUT items ($('X').first() source) */
    public array $nodeOutputs = [];

    public ?string $pointer = null;

    /** @var list<array{code: int, body: array<string, mixed>}> */
    public array $responses = [];

    public bool $finished = false;

    public ?int $createdNumber = null;

    public function bind(SoftAlertWorkflowReplay $replay): void
    {
        $this->replay = $replay;
    }

    /** Phase L: secret gate + list + title match + existing-branch decision. */
    public function runLookup(): void
    {
        $this->requireReplay()->runLookup($this);
    }

    /** Phase C: create the issue (and nothing else). */
    public function runCreate(): void
    {
        $this->requireReplay()->runCreate($this);
    }

    /** Phase R: reconcile list + duplicate decision + close/respond. */
    public function runReconcile(): void
    {
        $this->requireReplay()->runReconcile($this);
    }

    /** @return list<array{code: int, body: array<string, mixed>}> */
    public function responses(): array
    {
        return $this->responses;
    }

    public function finished(): bool
    {
        return $this->finished;
    }

    public function createdNumber(): ?int
    {
        return $this->createdNumber;
    }

    private function requireReplay(): SoftAlertWorkflowReplay
    {
        if ($this->replay === null) {
            throw new WorkflowGlueException('delivery is not bound to a replay; start it via SoftAlertWorkflowReplay::startDelivery()');
        }

        return $this->replay;
    }
}
