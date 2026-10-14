<?php

declare(strict_types=1);

namespace Pest\Mutate\Subscribers;

use Pest\Mutate\Repositories\TelemetryRepository;
use Pest\Support\Container;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * Records the tests of the initial test run: the code coverage omits the tests that execute no
 * covered line, like a test checking the value of an enum case.
 *
 * @internal
 */
final class TrackExecutedTests implements PreparedSubscriber
{
    public function notify(Prepared $event): void
    {
        /** @var TelemetryRepository $telemetryRepository */
        $telemetryRepository = Container::getInstance()->get(TelemetryRepository::class);

        $telemetryRepository->executedTest($event->test()->id());
    }
}
