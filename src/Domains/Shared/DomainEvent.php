<?php

declare(strict_types=1);

namespace App\Domains\Shared;

/**
 * Marker interface for events recorded by an aggregate and released for dispatch
 * after the aggregate has been persisted.
 */
interface DomainEvent
{
}
