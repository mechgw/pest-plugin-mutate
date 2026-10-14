<?php

declare(strict_types=1);

use Pest\Mutate\Mutation;
use Pest\Mutate\Mutators\Number\IncrementInteger;
use Pest\Mutate\Mutators\String\NotEmptyStringToEmpty;
use Pest\Mutate\Support\EnumCaseTestFinder;
use Pest\Mutate\Support\MutationGenerator;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Fixtures\Enums\Single;

beforeEach(function (): void {
    $this->fixtures = dirname(__DIR__, 2).'/Fixtures/Enums';

    $this->mutations = fn (string $file, string $mutator = NotEmptyStringToEmpty::class): array => (new MutationGenerator)->generate(
        new SplFileInfo($file, '', ''),
        [$mutator],
    );

    $this->mutationOf = function (string $file, string $value): Mutation {
        $position = strpos((string) file_get_contents($file), $value);

        foreach (($this->mutations)($file) as $mutation) {
            if ($mutation->startFilePos === $position) {
                return $mutation;
            }
        }

        throw new Exception("No mutation of {$value}");
    };

    $this->lineOf = function (string $file, string $needle): int {
        foreach ((array) file($file) as $index => $line) {
            if (str_contains((string) $line, $needle)) {
                return $index + 1;
            }
        }

        throw new Exception("{$needle} not found");
    };

    $this->temporaryFile = function (string $contents): string {
        $file = (string) tempnam(sys_get_temp_dir(), 'pest-mutate');

        file_put_contents($file, $contents);

        return (string) realpath($file);
    };

    $this->sameLine = ($this->temporaryFile)(<<<'PHP'
        <?php

        namespace Tests\Fixtures\Enums\Generated;

        enum SameLine: string
        {
            case First = 'FIRST'; case Second = 'SECOND';
        }
        PHP);

    $this->sameLineTest = ($this->temporaryFile)(<<<'PHP'
        <?php

        use Tests\Fixtures\Enums\Generated\SameLine;

        it('checks the second case', function () {
            expect(SameLine::Second->value)->toBe('SECOND');
        });
        PHP);
});

it('ignores mutations outside the value of a string-backed enum case', function (): void {
    $finder = new EnumCaseTestFinder([], []);

    expect($finder->candidateTestIds(($this->mutationOf)($this->fixtures.'/PublicationLabel.php', "'unknown'")))->toBeNull()
        ->and(($this->mutations)($this->fixtures.'/Priority.php', IncrementInteger::class))->toHaveCount(1)
        ->and($finder->candidateTestIds(($this->mutations)($this->fixtures.'/Priority.php', IncrementInteger::class)[0]))->toBeNull();
});

it('finds the tests covering any line of the statement referencing the case', function (): void {
    $statusLabel = (string) realpath($this->fixtures.'/PublicationLabel.php');

    $finder = new EnumCaseTestFinder([
        // the reference is on the line before the covered one
        $statusLabel => [($this->lineOf)($statusLabel, '->value;') => ['P\\Tests\\PublicationLabelTest::__pest_evaluable_it_works']],
    ], []);

    expect($finder->candidateTestIds(($this->mutationOf)($this->fixtures.'/Status.php', "'draft'")))
        ->toBe(['P\\Tests\\PublicationLabelTest::__pest_evaluable_it_works'])
        ->and($finder->candidateTestIds(($this->mutationOf)($this->fixtures.'/Status.php', "'published'")))
        ->toBe([]);
});

it('ignores references in statements without coverage', function (): void {
    $statusLabel = (string) realpath($this->fixtures.'/PublicationLabel.php');

    $finder = new EnumCaseTestFinder([
        $statusLabel => [($this->lineOf)($statusLabel, "'unknown'") => ['P\\Tests\\PublicationLabelTest::__pest_evaluable_it_works']],
    ], []);

    expect($finder->candidateTestIds(($this->mutationOf)($this->fixtures.'/Status.php', "'draft'")))->toBe([])
        ->and($finder->referencedCaseValues($this->fixtures.'/Status.php'))->toBe([]);
});

it('considers that a static call creating a case may return any case', function (): void {
    $statusLabel = (string) realpath($this->fixtures.'/PublicationLabel.php');

    $finder = new EnumCaseTestFinder([
        $statusLabel => [($this->lineOf)($statusLabel, '::tryFrom(') => ['P\\Tests\\PublicationLabelTest::__pest_evaluable_it_works']],
    ], []);

    expect($finder->candidateTestIds(($this->mutationOf)($this->fixtures.'/Status.php', "'draft'")))
        ->toBe(['P\\Tests\\PublicationLabelTest::__pest_evaluable_it_works'])
        ->and($finder->candidateTestIds(($this->mutationOf)($this->fixtures.'/Status.php', "'published'")))
        ->toBe(['P\\Tests\\PublicationLabelTest::__pest_evaluable_it_works']);
});

it('resolves self references inside the enum', function (): void {
    $status = (string) realpath($this->fixtures.'/Status.php');

    $finder = new EnumCaseTestFinder([
        $status => [($this->lineOf)($status, 'self::Published') => ['P\\Tests\\StatusTest::__pest_evaluable_it_works']],
    ], []);

    expect($finder->candidateTestIds(($this->mutationOf)($status, "'published'")))
        ->toBe(['P\\Tests\\StatusTest::__pest_evaluable_it_works'])
        ->and($finder->candidateTestIds(($this->mutationOf)($status, "'draft'")))
        ->toBe([]);
});

it('finds the class of a test file referencing the case and tells apart cases on the same line', function (): void {
    $finder = new EnumCaseTestFinder([], [$this->sameLineTest => 'P\\Tests\\SameLineTest']);

    $first = ($this->mutationOf)($this->sameLine, "'FIRST'");
    $second = ($this->mutationOf)($this->sameLine, "'SECOND'");

    expect($first->startLine)->toBe($second->startLine)
        ->and($finder->candidateTestIds($second))->toBe(['P\\Tests\\SameLineTest::'])
        ->and($finder->candidateTestIds($first))->toBe([]);
});

it('runs every selected test for a mutation cached without its position', function (): void {
    $second = ($this->mutationOf)($this->sameLine, "'SECOND'");

    $cachedByPreviousVersion = new Mutation(
        $second->file,
        $second->id,
        $second->mutator,
        $second->startLine,
        $second->endLine,
        $second->diff,
        $second->modifiedSourcePath,
    );

    $finder = new EnumCaseTestFinder([], [$this->sameLineTest => 'P\\Tests\\SameLineTest']);

    expect($finder->candidateTestIds($cachedByPreviousVersion))->toBe([]);
});

it('returns the position of the referenced case values only', function (): void {
    $finder = new EnumCaseTestFinder([], [$this->sameLineTest => 'P\\Tests\\SameLineTest']);

    $second = ($this->mutationOf)($this->sameLine, "'SECOND'");
    $contents = (string) file_get_contents($this->sameLine);

    expect($finder->referencedCaseValues($this->sameLine))->toBe([[
        'startFilePos' => strpos($contents, "'SECOND'"),
        'endFilePos' => strpos($contents, "'SECOND'") + strlen("'SECOND'") - 1,
        'startLine' => $second->startLine,
        'endLine' => $second->startLine,
    ]])
        ->and($second->startFilePos)->toBe(strpos($contents, "'SECOND'"))
        ->and($finder->referencedCaseValues($this->fixtures.'/Status.php'))->toBe([])
        ->and($finder->referencedCaseValues($this->fixtures.'/PublicationLabel.php'))->toBe([]);
});

it('finds the loaded test files', function (): void {
    expect(Single::Only->value)->toBe('only');

    $finder = EnumCaseTestFinder::fromLoadedTests([]);

    expect($finder->candidateTestIds(($this->mutationOf)($this->fixtures.'/Single.php', "'only'")))
        ->toContain($this::class.'::');
});
