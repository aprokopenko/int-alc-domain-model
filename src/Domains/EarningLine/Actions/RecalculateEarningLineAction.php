<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\Actions;

use App\Domains\EarningLine\EarningLineRepository;
use App\Domains\EarningLine\RecalculationOutcome;
use App\Domains\EarningLine\ValueObjects\RecalculateEarningLineData;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class RecalculateEarningLineAction
{
    public function __construct(
        private EarningLineRepository $repository,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function execute(RecalculateEarningLineData $data): RecalculationOutcome
    {
        $line = $this->repository->get($data->lineId);

        $outcome = $line->recalculate($data->newAmount);

        $this->repository->save($line);

        foreach ($line->releaseEvents() as $event) {
            $this->dispatcher->dispatch($event);
        }

        return $outcome;
    }
}
