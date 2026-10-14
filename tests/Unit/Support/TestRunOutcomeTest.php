<?php

declare(strict_types=1);

use Pest\Mutate\Support\TestRunOutcome;

function junitReport(string $testcases): string
{
    return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites>
          <testsuite name="Tests\Feature\StatusTest" tests="2" assertions="2" errors="0" failures="0" skipped="0" time="0.01">
            {$testcases}
          </testsuite>
        </testsuites>
        XML;
}

it('passes when every test passed', function (): void {
    expect(TestRunOutcome::fromJunit(0, junitReport('<testcase name="a"/><testcase name="b"/>')))
        ->toBe(TestRunOutcome::Passed);
});

it('fails when a test failed or errored', function (string $defect): void {
    expect(TestRunOutcome::fromJunit(1, junitReport('<testcase name="a"/><testcase name="b">'.$defect.'</testcase>')))
        ->toBe(TestRunOutcome::Failed);
})->with([
    'failure' => '<failure type="PHPUnit\Framework\ExpectationFailedException">Failed asserting that two strings are identical.</failure>',
    'error' => '<error type="ValueError">"" is not a valid backing value</error>',
]);

it('is inconclusive when no test failed', function (?int $exitCode, string $junit): void {
    expect(TestRunOutcome::fromJunit($exitCode, $junit))
        ->toBe(TestRunOutcome::Inconclusive);
})->with([
    'test runner warning' => [1, junitReport('<testcase name="a"/>')],
    'fatal error' => [255, ''],
    'process not started' => [1, ''],
    'process terminated by a signal' => [null, junitReport('<testcase name="a"/>')],
    'truncated report' => [255, '<?xml version="1.0"?><testsuites><testsuite>'],
    'no test run' => [0, junitReport('')],
    'success without report' => [0, ''],
    'success with a failure' => [0, junitReport('<testcase name="a"><failure/></testcase>')],
]);
