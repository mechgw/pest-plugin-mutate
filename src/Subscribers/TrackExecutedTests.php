<?php

declare(strict_types=1);

namespace Pest\Mutate\Subscribers;

use Pest\Mutate\Plugins\Mutate;
use Pest\Mutate\Repositories\TelemetryRepository;
use Pest\Mutate\Support\ExecutedTestsRecord;
use Pest\Plugins\Parallel;
use Pest\Support\Container;
use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;

/**
 * Records the tests of the initial test run: the code coverage omits the tests that execute no
 * covered line, like a test checking the value of an enum case. The workers of a parallel run
 * record them in a file shared with the main process. A test that cannot be recorded fails the
 * test run, since an incomplete record would silently leave out the enum cases it uses.
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

        $file = getenv(Mutate::ENV_EXECUTED_TESTS_FILE);

        if (! is_string($file) || ! Parallel::isWorker() || getenv(Mutate::ENV_MUTATION_TESTING) !== false) {
            return;
        }

        ExecutedTestsRecord::append($file, $event->test()->id());
    }
}
