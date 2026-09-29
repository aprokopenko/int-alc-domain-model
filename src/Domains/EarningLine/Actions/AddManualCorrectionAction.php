<?php

declare(strict_types=1);

namespace App\Domains\EarningLine\Actions;

use App\Domains\EarningLine\EarningLineRepository;
use App\Domains\EarningLine\ValueObjects\AddManualCorrectionData;
use App\Domains\EarningLine\ValueObjects\Comment;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class AddManualCorrectionAction
{
    public function __construct(
        private EarningLineRepository $repository,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    public function execute(AddManualCorrectionData $data): void
    {
        $line = $this->repository->get($data->lineId);

        $line->addCorrection(
            $data->adjustment,
            new Comment($data->comment),
            $data->createdBy,
            $data->createdAt,
        );

        $this->repository->save($line);

        foreach ($line->releaseEvents() as $event) {
            $this->dispatcher->dispatch($event);
        }
    }
}
