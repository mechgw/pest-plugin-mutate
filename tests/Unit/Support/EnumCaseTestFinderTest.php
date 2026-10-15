<?php

declare(strict_types=1);

use Pest\Mutate\Mutation;
use Pest\Mutate\Mutators\Number\IncrementInteger;
use Pest\Mutate\Mutators\String\NotEmptyStringToEmpty;
use Pest\Mutate\Support\EnumCaseTestFinder;
use Pest\Mutate\Support\MutationGenerator;
use Symfony\Component\Finder\SplFileInfo;
use Tests\Fixtures\Enums\PublicationCheck;
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

        it('checks the first case', function () {
            expect(SameLine::First->value)->toBe('FIRST');
        });
        PHP);

    // the lines of the code of the tests, as Pest reports them: the lines of their closure
    $this->testCase = fn (string $file, string $description, array $ids): array => [
        'ids' => $ids,
        'startLine' => ($this->lineOf)($file, $description),
        'endLine' => ($this->lineOf)($file, $description) + 2,
    ];

    // only the test of the second case was executed, e.g. with --filter
    $this->sameLineTestCases = [$this->sameLineTest => [
        ($this->testCase)($this->sameLineTest, 'checks the second case', ['P\\Tests\\SameLineTest::__pest_evaluable_it_checks_the_second_case']),
        ($this->testCase)($this->sameLineTest, 'checks the first case', []),
    ]];
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

it('finds the executed tests whose code references the case and tells apart cases on the same line', function (): void {
    $finder = new EnumCaseTestFinder([], $this->sameLineTestCases);

    $first = ($this->mutationOf)($this->sameLine, "'FIRST'");
    $second = ($this->mutationOf)($this->sameLine, "'SECOND'");

    expect($first->startLine)->toBe($second->startLine)
        ->and($finder->candidateTestIds($second))->toBe(['P\\Tests\\SameLineTest::__pest_evaluable_it_checks_the_second_case'])
        // referenced only by a test of the same file that was not executed
        ->and($finder->candidateTestIds($first))->toBe([]);
});

it('finds all the executed tests of the file for a reference outside of any test', function (): void {
    $test = ($this->temporaryFile)(<<<'PHP'
        <?php

        use Tests\Fixtures\Enums\Generated\SameLine;

        beforeEach(function () {
            $this->first = SameLine::First->value;
        });

        it('checks the first case', function () {
            expect($this->first)->toBe('FIRST');
        });

        it('does something else', function () {
            expect(true)->toBeTrue();
        });
        PHP);

    $finder = new EnumCaseTestFinder([], [$test => [
        ($this->testCase)($test, 'checks the first case', ['T::first']),
        ($this->testCase)($test, 'does something else', []),
    ]]);

    expect($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'FIRST'")))->toBe(['T::first']);
});

it('does not consider a helper of the test file as run by the executed tests', function (): void {
    $test = ($this->temporaryFile)(<<<'PHP'
        <?php

        use Tests\Fixtures\Enums\Generated\SameLine;

        function second(): string
        {
            return SameLine::Second->value;
        }

        it('checks the first case', function () {
            expect(SameLine::First->value)->toBe('FIRST');
        });
        PHP);

    $finder = new EnumCaseTestFinder([], [$test => [
        ($this->testCase)($test, 'checks the first case', ['T::first']),
    ]]);

    expect($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'FIRST'")))->toBe(['T::first'])
        ->and($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'SECOND'")))->toBe([]);
});

it('finds the executed tests calling a helper of the test file, directly or not', function (): void {
    $test = ($this->temporaryFile)(<<<'PHP'
        <?php

        use Tests\Fixtures\Enums\Generated\SameLine;

        function firstValue(): string
        {
            return SameLine::First->value;
        }

        function firstValueThroughAnotherHelper(): string
        {
            return firstValue();
        }

        function secondValue(): string
        {
            return secondValue() === '' ? '' : SameLine::Second->value;
        }

        it('checks the first case', function () {
            expect(firstValueThroughAnotherHelper())->toBe('FIRST');
        });

        it('checks the second case', function () {
            expect(secondValue())->toBe('SECOND');
        });
        PHP);

    $finder = new EnumCaseTestFinder([], [$test => [
        ($this->testCase)($test, 'checks the first case', ['T::first']),
        // calls the recursive helper, but was not executed
        ($this->testCase)($test, 'checks the second case', []),
    ]]);

    expect($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'FIRST'")))->toBe(['T::first'])
        ->and($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'SECOND'")))->toBe([]);
});

it('finds the executed tests around which a hook calls a helper', function (): void {
    $test = ($this->temporaryFile)(<<<'PHP'
        <?php

        use Tests\Fixtures\Enums\Generated\SameLine;

        function secondValue(): string
        {
            return SameLine::Second->value;
        }

        beforeEach(function () {
            $this->second = secondValue();
        });

        it('checks the second case', function () {
            expect($this->second)->toBe('SECOND');
        });
        PHP);

    $finder = new EnumCaseTestFinder([], [$test => [
        ($this->testCase)($test, 'checks the second case', ['T::second']),
    ]]);

    expect($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'SECOND'")))->toBe(['T::second']);
});

it('finds the executed tests calling a helper method of a PHPUnit test class', function (): void {
    $test = ($this->temporaryFile)(<<<'PHP_WRAP'
    <?php
    
    use PHPUnit\Framework\TestCase;
    use Tests\Fixtures\Enums\Generated\SameLine;
    
    final class SameLineHelperCheck extends TestCase
    {
        public function test_first(): void
        {
            self::assertSame('FIRST', $this->first());
        }
    
        public function test_second(): void
        {
            self::assertSame('SECOND', self::second());
        }
    
        private function first(): string
        {
            return SameLine::First->value;
        }
    
        private static function second(): string
        {
            return SameLine::Second->value;
        }
    }
    PHP_WRAP);

    $finder = new EnumCaseTestFinder([], [$test => [
        ['ids' => ['SameLineHelperCheck::test_first'], 'startLine' => ($this->lineOf)($test, 'function test_first'), 'endLine' => ($this->lineOf)($test, 'function test_first') + 3],
        ['ids' => [], 'startLine' => ($this->lineOf)($test, 'function test_second'), 'endLine' => ($this->lineOf)($test, 'function test_second') + 3],
    ]]);

    expect($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'FIRST'")))->toBe(['SameLineHelperCheck::test_first'])
        ->and($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'SECOND'")))->toBe([]);
});

it('finds the executed tests of the describe block of a hook', function (): void {
    $test = ($this->temporaryFile)(<<<'PHP'
        <?php

        use Tests\Fixtures\Enums\Generated\SameLine;

        describe('second', function () {
            beforeEach(function () {
                $this->second = SameLine::Second->value;
            });

            it('checks the second case', function () {
                expect($this->second)->toBe('SECOND');
            });
        });

        it('checks the first case', function () {
            expect(SameLine::First->value)->toBe('FIRST');
        });
        PHP);

    $finder = new EnumCaseTestFinder([], [$test => [
        ($this->testCase)($test, 'checks the second case', ['T::second']),
        ($this->testCase)($test, 'checks the first case', ['T::first']),
    ]]);

    expect($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'SECOND'")))->toBe(['T::second'])
        ->and($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'FIRST'")))->toBe(['T::first']);
});

it('finds the executed tests of the class of a PHPUnit hook', function (): void {
    $test = ($this->temporaryFile)(<<<'PHP_WRAP'
    <?php
    
    use PHPUnit\Framework\TestCase;
    use Tests\Fixtures\Enums\Generated\SameLine;
    
    final class SameLineCheck extends TestCase
    {
        private string $second;
    
        protected function setUp(): void
        {
            $this->second = SameLine::Second->value;
        }
    
        public function test_second(): void
        {
            self::assertSame('SECOND', $this->second);
        }
    
        private function first(): string
        {
            return SameLine::First->value;
        }
    }
    PHP_WRAP);

    $finder = new EnumCaseTestFinder([], [$test => [
        ['ids' => ['SameLineCheck::test_second'], 'startLine' => ($this->lineOf)($test, 'function test_second'), 'endLine' => ($this->lineOf)($test, 'function test_second') + 3],
    ]]);

    expect($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'SECOND'")))->toBe(['SameLineCheck::test_second'])
        ->and($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'FIRST'")))->toBe([]);
});

it('finds the test referencing the case in its dataset', function (): void {
    $test = ($this->temporaryFile)(<<<'PHP'
        <?php

        use Tests\Fixtures\Enums\Generated\SameLine;

        it('checks a case', function (string $value) {
            expect($value)->not->toBeEmpty();
        })->with([
            SameLine::First->value,
        ]);

        it('checks another case', function (string $value) {
            expect($value)->not->toBeEmpty();
        })->with([
            SameLine::Second->value,
        ]);
        PHP);

    $finder = new EnumCaseTestFinder([], [$test => [
        ($this->testCase)($test, 'checks a case', ['T::a#0']),
        ($this->testCase)($test, 'checks another case', []),
    ]]);

    expect($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'FIRST'")))->toBe(['T::a#0'])
        ->and($finder->candidateTestIds(($this->mutationOf)($this->sameLine, "'SECOND'")))->toBe([]);
});

it('recognizes the keywords of a string-backed enum in any case', function (): void {
    $enum = ($this->temporaryFile)(<<<'PHP'
        <?php

        namespace Tests\Fixtures\Enums\Generated;

        ENUM UpperCase: STRING
        {
            case Only = 'ONLY';
        }
        PHP);

    expect(new EnumCaseTestFinder([], [])->candidateTestIds(($this->mutationOf)($enum, "'ONLY'")))->toBe([]);
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

    $finder = new EnumCaseTestFinder([], $this->sameLineTestCases);

    expect($finder->candidateTestIds($cachedByPreviousVersion))->toBe([]);
});

it('returns the position of the referenced case values only', function (): void {
    $finder = new EnumCaseTestFinder([], $this->sameLineTestCases);

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

it('locates the code of the executed Pest tests', function (): void {
    expect(Single::Only->value)->toBe('only');

    $id = $this::class.'::'.$this->name();

    expect(EnumCaseTestFinder::fromExecutedTests([], [$id])->candidateTestIds(($this->mutationOf)($this->fixtures.'/Single.php', "'only'")))
        ->toBe([$id])
        ->and(EnumCaseTestFinder::fromExecutedTests([], [])->candidateTestIds(($this->mutationOf)($this->fixtures.'/Single.php', "'only'")))
        ->toBe([]);
});

it('locates the code of the executed PHPUnit tests', function (): void {
    class_exists(PublicationCheck::class);

    $finder = EnumCaseTestFinder::fromExecutedTests([], [PublicationCheck::class.'::test_draft']);

    expect($finder->candidateTestIds(($this->mutationOf)($this->fixtures.'/Status.php', "'draft'")))
        ->toBe([PublicationCheck::class.'::test_draft'])
        // referenced only by a test method that was not executed
        ->and($finder->candidateTestIds(($this->mutationOf)($this->fixtures.'/Status.php', "'published'")))
        ->toBe([]);
});
