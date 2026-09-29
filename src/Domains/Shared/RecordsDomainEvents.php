<?php

declare(strict_types=1);

namespace App\Domains\Shared;

trait RecordsDomainEvents
{
    /** @var DomainEvent[] */
    private array $recordedEvents = [];

    private function record(DomainEvent $event): void
    {
        $this->recordedEvents[] = $event;
    }

    /**
     * Returns and clears all events recorded since the last call.
     *
     * @return DomainEvent[]
     */
    public function releaseEvents(): array
    {
        $events = $this->recordedEvents;
        $this->recordedEvents = [];

        return $events;
    }
}
