<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Generated\SameLine;
use Tests\Fixtures\Enums\PublicationLabel;
use Tests\Fixtures\Enums\Single;
use Tests\Fixtures\Enums\Status;

it('kills the mutants of the enum values checked by a test', function (array $arguments): void {
    expect(enumMutationTestSummary('Checked', Status::class, $arguments))->toBe('2 tested');
})->with([
    'all the mutations' => [[]],
    'covered mutations only' => [['--covered-only']],
    'in parallel' => [['--parallel']],
    'covered mutations only in parallel' => [['--covered-only', '--parallel']],
]);

it('lets the mutants of the enum values read by a test without checking them escape', function (): void {
    expect(enumMutationTestSummary('Unchecked', Status::class))->toBe('2 untested, 0 tested');
});

it('lets the mutants of the enum values used by no test escape', function (): void {
    expect(enumMutationTestSummary('Unreferenced', Status::class))->toBe('2 untested, 0 tested')
        ->and(enumMutationTestSummary('Unreferenced', Status::class, ['--covered-only']))->toBe('0 tested');
});

it('kills the mutants of the enum values used by covered source code', function (): void {
    // only Draft is referenced by the code covered by the test
    expect(enumMutationTestSummary('SourceReference', Status::class))->toBe('1 untested, 1 tested')
        ->and(enumMutationTestSummary('SourceReference', Status::class, ['--covered-only']))->toBe('1 tested');
});

it('tells apart the cases declared on the same line', function (): void {
    expect(enumMutationTestSummary('SameLineSecond', SameLine::class))->toBe('1 untested, 1 tested')
        // a mutant of the first case would escape
        ->and(enumMutationTestSummary('SameLineSecond', SameLine::class, ['--covered-only']))->toBe('1 tested')
        ->and(enumMutationTestSummary('SameLineFirst', SameLine::class, ['--covered-only']))->toBe('1 tested')
        // the cached mutations of the line are filtered again for the tested case
        ->and(enumMutationTestSummary('SameLineSecond', SameLine::class, ['--covered-only']))->toBe('1 tested');
});

it('only considers the tests selected in a test file', function (): void {
    $filter = '--filter=checks the draft value';

    // the test of Published is in the same file, but not selected
    expect(enumMutationTestSummary('Filtered', Status::class, [$filter, '--covered-only']))->toBe('1 tested')
        ->and(enumMutationTestSummary('Filtered', Status::class, [$filter]))->toBe('1 untested, 1 tested');
});

it('finds the selected tests executing no covered line', function (array $arguments): void {
    // the enum is loaded before the test, so the code coverage has no trace of the test
    expect(enumMutationTestSummary('Preloaded', Status::class, ['--covered-only', ...$arguments]))->toBe('1 tested');
})->with([
    'in this process' => [[]],
    'in parallel workers' => [['--parallel']],
]);

it('aborts before running the tests when the record of the executed tests cannot be created', function (): void {
    $process = enumMutationTestProcess('Preloaded', Status::class, ['--covered-only', '--parallel'], [
        'PEST_MUTATE_EXECUTED_TESTS_FILE' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'pest-mutate-missing-directory'.DIRECTORY_SEPARATOR.'executed-tests',
    ]);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->not->toContain('Mutations:')
        ->and($process->getOutput().$process->getErrorOutput())->toContain('in which the parallel workers record the tests they execute');
});

it('does not test the mutations when a parallel worker cannot record an executed test', function (): void {
    // a directory cannot be written as a file
    $process = enumMutationTestProcess('Preloaded', Status::class, ['--covered-only', '--parallel'], [
        'PEST_MUTATE_EXECUTED_TESTS_FILE' => sys_get_temp_dir(),
    ]);

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->not->toContain('Mutations:');
});

it('does not test the mutations when a parallel worker cannot write to a readable record', function (): void {
    $file = (string) tempnam(sys_get_temp_dir(), 'pest-mutate-read-only');
    chmod($file, 0444);

    try {
        // the main process can read the record, so only the worker notices the failure
        $process = enumMutationTestProcess('Preloaded', Status::class, ['--covered-only', '--parallel'], [
            'PEST_MUTATE_EXECUTED_TESTS_FILE' => $file,
        ]);
    } finally {
        chmod($file, 0644);
        if (is_file($file)) {
            unlink($file);
        }
    }

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->not->toContain('Mutations:');
});

it('aborts when the record of the tests executed by the parallel workers is cut', function (): void {
    $file = (string) tempnam(sys_get_temp_dir(), 'pest-mutate-cut-record');
    // a line cut before its end, as left by an interrupted write
    file_put_contents($file, 'P\\Tests\\tests\\Enums\\Preloaded\\StatusValueTest::__pest_eval');

    $process = enumMutationTestProcess('Preloaded', Status::class, ['--covered-only', '--parallel'], [
        'PEST_MUTATE_EXECUTED_TESTS_FILE' => $file,
    ]);

    if (is_file($file)) {
        unlink($file);
    }

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput())->not->toContain('Mutations:')
        ->and($process->getOutput())->toContain('Unable to read a complete record of the tests executed by the parallel workers');
});

it('considers a helper of a test file as used by the selected tests calling it', function (): void {
    // each test reads a case through its own helper; the tests are not covered
    expect(enumMutationTestSummary('HelperCalled', Status::class, ['--covered-only']))->toBe('2 tested')
        ->and(enumMutationTestSummary('HelperCalled', Status::class, ['--covered-only', '--filter=checks the draft value']))->toBe('1 tested');
});

it('skips the cases used only through a helper of another file or a closure held in a variable', function (): void {
    // a documented limit: these references are not followed, so --covered-only conservatively
    // leaves their cases out, while all the selected tests still verify them otherwise
    expect(enumMutationTestSummary('OutOfScope', Status::class, ['--covered-only']))->toBe('0 tested')
        ->and(enumMutationTestSummary('OutOfScope', Status::class))->toBe('2 tested');
});

it('does not consider a helper of a test file as used by the selected tests', function (): void {
    // the helper referencing Published is called by no test
    expect(enumMutationTestSummary('Helper', Status::class, ['--covered-only']))->toBe('1 tested')
        ->and(enumMutationTestSummary('Helper', Status::class))->toBe('1 untested, 1 tested');
});

it('runs all the selected tests only when the tests referencing the case do not kill the mutant', function (): void {
    $sentinel = (string) tempnam(sys_get_temp_dir(), 'pest-mutate-sentinel');

    $summary = enumMutationTestSummary('Candidates', Status::class, env: ['PEST_MUTATE_SENTINEL' => $sentinel]);

    // the initial run, then all the tests for Published, which no test references
    expect($summary)->toBe('1 untested, 1 tested')
        ->and(file($sentinel))->toHaveCount(2);

    unlink($sentinel);
});

it('runs all the selected tests when the tests referencing the case do not kill the mutant', function (): void {
    // Draft is read without being checked, and checked through a constant that is never covered
    expect(enumMutationTestSummary('Fallback', Status::class))->toBe('1 untested, 1 tested');
});

it('does not consider a test run failing before running any test as killing the mutant', function (): void {
    $output = enumMutationTestOutput('InfrastructureFailure', Single::class);

    expect($output)->toContain('Mutations: 1 uncovered, 0 tested')
        ->toContain('Not verified: the tests ended with exit code 2 and no JUnit report.');
});

it('does not consider all the selected tests timing out as killing the mutant', function (): void {
    $output = enumMutationTestOutput('Timeout', Single::class);

    expect($output)->toContain('Mutations: 1 uncovered, 0 tested')
        ->toContain('Not verified: the tests did not finish within the time limit of');
});

it('does not consider all the selected tests exceeding a raised time limit as killing the mutant', function (): void {
    $process = enumMutationTestProcess('SlowUnchecked', Single::class, ['--enum-timeout=10', '--min=100']);

    // the reason follows the line of the mutant, so that a wrapper can tell it from an uncovered line
    expect($process->getOutput())->toContain('Mutations: 1 uncovered, 0 tested')
        ->toContain('Score:     0.00%')
        ->toMatch('/UNCOVERED .+ - ID: \S+\R  Not verified: the tests did not finish within the time limit of 10 s \(--enum-timeout\)\. A timeout is no evidence of a killed mutant\./')
        ->and($process->getExitCode())->toBe(1);
});

it('kills the mutant when all the selected tests fail within the configured time limit', function (): void {
    expect(enumMutationTestSummary('SlowChecked', Single::class, ['--enum-timeout=60']))->toBe('1 tested')
        // the default time limit, derived from the initial test run, is too short
        ->and(enumMutationTestSummary('SlowChecked', Single::class))->toBe('1 uncovered, 0 tested');
});

it('keeps reporting the uncovered lines of other code as uncovered', function (): void {
    expect(enumMutationTestSummary('Uncovered', PublicationLabel::class))->toBe('1 uncovered, 0 tested')
        ->and(enumMutationTestSummary('Uncovered', PublicationLabel::class, ['--covered-only']))->toBe('0 tested');
});
