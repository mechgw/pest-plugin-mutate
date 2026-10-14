<?php

declare(strict_types=1);

use Pest\Mutate\MutationTest;
use Pest\Mutate\Mutators\String\NotEmptyStringToEmpty;
use Pest\Mutate\Repositories\TelemetryRepository;
use Pest\Mutate\Support\Configuration\Configuration;
use Pest\Mutate\Support\EnumCaseTestFinder;
use Pest\Mutate\Support\MutationGenerator;
use Pest\Mutate\Support\MutationTestResult;
use Pest\Support\Container;
use Symfony\Component\Finder\SplFileInfo;

it('verifies the value of an enum case even when its line is covered', function (): void {
    [$mutation] = (new MutationGenerator)->generate(
        new SplFileInfo(__DIR__.'/../Fixtures/Enums/Single.php', '', ''),
        [NotEmptyStringToEmpty::class],
    );

    Container::getInstance()->get(TelemetryRepository::class)->initialTestSuiteDuration(1);

    $test = new MutationTest($mutation);

    // php-code-coverage never reports such a line as executed, but a covered line must not
    // turn the verification of the value back into the run of the tests covering the line
    $started = $test->start(
        [(string) $mutation->file->getRealPath() => [$mutation->startLine => ['P\\Tests\\SingleTest::__pest_evaluable_it_covers_the_line']]],
        new Configuration(false, [], [], [], [], false, 1, false, null, false, false, false, null, false, false),
        // a test run that passes without running any test, nor writing a JUnit report
        [PHP_BINARY, '-r', 'exit(0);', '--'],
        enumCaseTestFinder: new EnumCaseTestFinder([], []),
    );

    while (! $test->hasFinished()) {
        usleep(1000);
    }

    expect($started)->toBeTrue()
        ->and($test->result())->toBe(MutationTestResult::Uncovered)
        ->and($test->inconclusiveReason())->toBe('Not verified: the tests ended with exit code 0 and no JUnit report.');
});
