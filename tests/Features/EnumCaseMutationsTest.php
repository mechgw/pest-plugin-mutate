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
    $path = '--path='.dirname(sameLineEnum());

    expect(enumMutationTestSummary('SameLineSecond', SameLine::class, [$path]))->toBe('1 untested, 1 tested')
        // a mutant of the first case would escape
        ->and(enumMutationTestSummary('SameLineSecond', SameLine::class, [$path, '--covered-only']))->toBe('1 tested')
        ->and(enumMutationTestSummary('SameLineFirst', SameLine::class, [$path, '--covered-only']))->toBe('1 tested')
        // the cached mutations of the line are filtered again for the tested case
        ->and(enumMutationTestSummary('SameLineSecond', SameLine::class, [$path, '--covered-only']))->toBe('1 tested');
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
    expect(enumMutationTestSummary('InfrastructureFailure', Single::class))->toBe('1 uncovered, 0 tested');
});

it('reports a timeout when all the selected tests time out', function (): void {
    expect(enumMutationTestSummary('Timeout', Single::class))->toBe('1 timeout, 0 tested');
});

it('keeps reporting the uncovered lines of other code as uncovered', function (): void {
    expect(enumMutationTestSummary('Uncovered', PublicationLabel::class))->toBe('1 uncovered, 0 tested')
        ->and(enumMutationTestSummary('Uncovered', PublicationLabel::class, ['--covered-only']))->toBe('0 tested');
});
