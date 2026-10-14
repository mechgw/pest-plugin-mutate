<?php

declare(strict_types=1);

namespace Pest\Mutate\Support;

use Pest\Mutate\Factories\NodeTraverserFactory;
use Pest\Mutate\Mutation;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\NodeFinder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The value of a case of a string-backed enum is not an executable line, so line coverage
 * never tells which tests depend on it. This finds the tests that may observe such a value:
 * the tests covering a statement that references the case, and the tests of a loaded test
 * file that references the case.
 *
 * These tests are only candidates, run first to kill a mutant quickly: when none of them
 * fails, the mutant must still be verified against all the originally selected tests.
 */
final class EnumCaseTestFinder
{
    /**
     * A call to one of these methods may return any case of the enum.
     */
    private const array METHODS_REFERENCING_ALL_CASES = ['from', 'tryfrom', 'cases'];

    private const string ALL_CASES = '*';

    /**
     * @var array<string, array<int, array{enum: string, case: string, startFilePos: int, endFilePos: int, startLine: int, endLine: int}>>
     */
    private array $caseValues = [];

    /**
     * @var array<string, array<string, array<int, string>>>
     */
    private array $testIdsByEnum = [];

    /**
     * @param  array<string, array<int, array<int, string>>>  $coveredLines
     * @param  array<string, string>  $testFiles  the test class declared by each loaded test file
     */
    public function __construct(
        private readonly array $coveredLines,
        private readonly array $testFiles,
    ) {}

    /**
     * @param  array<string, array<int, array<int, string>>>  $coveredLines
     */
    public static function fromLoadedTests(array $coveredLines): self
    {
        $testFiles = [];

        foreach (get_declared_classes() as $class) {
            if (! is_subclass_of($class, TestCase::class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract()) {
                continue;
            }

            // classes of Pest test files are evaluated, their file is kept in a static property
            $file = $reflection->hasProperty('__filename') && $reflection->getProperty('__filename')->isStatic()
                ? $reflection->getStaticPropertyValue('__filename')
                : $reflection->getFileName();

            if (is_string($file) && is_file($file)) {
                $testFiles[$file] = $class;
            }
        }

        return new self($coveredLines, $testFiles);
    }

    /**
     * Returns the ids of the tests to run first to kill the given mutation, or null when the
     * mutation is not in the value of a case of a string-backed enum.
     *
     * The id of a test referencing the case in its test file is its class name followed by
     * "::", to run every test of the class.
     *
     * @return array<int, string>|null
     */
    public function candidateTestIds(Mutation $mutation): ?array
    {
        $file = $mutation->file->getRealPath();

        if ($file === false) {
            return null;
        }

        foreach ($this->caseValues($file) as $caseValue) {
            if ($mutation->startFilePos === null) {
                // cached by a previous version: the mutated case cannot be told apart from
                // another one on the same line, every selected test has to run
                if ($mutation->startLine <= $caseValue['endLine'] && $mutation->endLine >= $caseValue['startLine']) {
                    return [];
                }

                continue;
            }

            if ($mutation->startFilePos >= $caseValue['startFilePos'] && $mutation->startFilePos <= $caseValue['endFilePos']) {
                return $this->testIdsReferencing($caseValue['enum'], $caseValue['case']);
            }
        }

        return null;
    }

    /**
     * Returns the position ranges of the case values of the string-backed enums in the given
     * file that are referenced by a covered statement or by a loaded test file.
     *
     * @return array<int, array{startFilePos: int, endFilePos: int, startLine: int, endLine: int}>
     */
    public function referencedCaseValues(string $file): array
    {
        $referenced = [];

        foreach ($this->caseValues($file) as $caseValue) {
            if ($this->testIdsReferencing($caseValue['enum'], $caseValue['case']) === []) {
                continue;
            }

            $referenced[] = [
                'startFilePos' => $caseValue['startFilePos'],
                'endFilePos' => $caseValue['endFilePos'],
                'startLine' => $caseValue['startLine'],
                'endLine' => $caseValue['endLine'],
            ];
        }

        return $referenced;
    }

    /**
     * @return array<int, array{enum: string, case: string, startFilePos: int, endFilePos: int, startLine: int, endLine: int}>
     */
    private function caseValues(string $file): array
    {
        if (isset($this->caseValues[$file])) {
            return $this->caseValues[$file];
        }

        $caseValues = [];

        $contents = is_file($file) ? file_get_contents($file) : false;

        if ($contents !== false && str_contains($contents, 'enum')) {
            foreach ((new NodeFinder)->findInstanceOf($this->parse($contents), Enum_::class) as $enum) {
                if ($enum->scalarType?->toString() !== 'string' || ! $enum->namespacedName instanceof Name) {
                    continue;
                }

                foreach ($enum->stmts as $stmt) {
                    if (! $stmt instanceof EnumCase || ! $stmt->expr instanceof Node) {
                        continue;
                    }

                    $caseValues[] = [
                        'enum' => $enum->namespacedName->toString(),
                        'case' => $stmt->name->toString(),
                        'startFilePos' => $stmt->expr->getStartFilePos(),
                        'endFilePos' => $stmt->expr->getEndFilePos(),
                        'startLine' => $stmt->expr->getStartLine(),
                        'endLine' => $stmt->expr->getEndLine(),
                    ];
                }
            }
        }

        return $this->caseValues[$file] = $caseValues;
    }

    /**
     * @return array<int, string>
     */
    private function testIdsReferencing(string $enum, string $case): array
    {
        $this->testIdsByEnum[$enum] ??= $this->indexTestIds($enum);

        return array_values(array_unique([
            ...$this->testIdsByEnum[$enum][$case] ?? [],
            ...$this->testIdsByEnum[$enum][self::ALL_CASES] ?? [],
        ]));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function indexTestIds(string $enum): array
    {
        $shortName = str_contains($enum, '\\') ? substr($enum, (int) strrpos($enum, '\\') + 1) : $enum;

        $testIds = [];

        foreach (array_unique([...array_keys($this->coveredLines), ...array_keys($this->testFiles)]) as $file) {
            $contents = is_file($file) ? file_get_contents($file) : false;

            // a reference, or the import it relies on, names the enum
            if ($contents === false || ! str_contains($contents, $shortName)) {
                continue;
            }

            foreach ($this->references($this->parse($contents), $enum) as [$case, $stmt]) {
                foreach ($this->coveredLines[$file] ?? [] as $line => $ids) {
                    if ($line >= $stmt->getStartLine() && $line <= $stmt->getEndLine()) {
                        $testIds[$case] = [...$testIds[$case] ?? [], ...$ids];
                    }
                }

                if (isset($this->testFiles[$file])) {
                    $testIds[$case][] = $this->testFiles[$file].'::';
                }
            }
        }

        return array_map(fn (array $ids): array => array_values(array_unique($ids)), $testIds);
    }

    /**
     * Returns the referenced case of the given enum, or "*" for any case, and the innermost
     * statement containing the reference.
     *
     * @param  array<int, Node>  $nodes
     * @return array<int, array{0: string, 1: Stmt}>
     */
    private function references(array $nodes, string $enum): array
    {
        $references = [];

        foreach ((new NodeFinder)->find($nodes, fn (Node $node): bool => $node instanceof ClassConstFetch || $node instanceof StaticCall) as $node) {
            /** @var ClassConstFetch|StaticCall $node */
            if (! $node->class instanceof Name || ! $node->name instanceof Identifier) {
                continue;
            }

            if ($this->resolveClassName($node->class) !== $enum) {
                continue;
            }

            $name = $node->name->toString();

            if ($node instanceof StaticCall) {
                if (! in_array(strtolower($name), self::METHODS_REFERENCING_ALL_CASES, true)) {
                    continue;
                }

                $name = self::ALL_CASES;
            } elseif ($name === 'class') {
                continue;
            }

            $stmt = $node->getAttribute('parent');

            while ($stmt instanceof Node && ! $stmt instanceof Stmt) {
                $stmt = $stmt->getAttribute('parent');
            }

            if ($stmt instanceof Stmt) {
                $references[] = [$name, $stmt];
            }
        }

        return $references;
    }

    private function resolveClassName(Name $name): ?string
    {
        if ($name->isSpecialClassName()) {
            if ($name->toLowerString() === 'parent') {
                return null;
            }

            $classLike = $name->getAttribute('parent');

            while ($classLike instanceof Node && ! $classLike instanceof ClassLike) {
                $classLike = $classLike->getAttribute('parent');
            }

            return $classLike instanceof ClassLike ? $classLike->namespacedName?->toString() : null;
        }

        $resolvedName = $name->getAttribute('resolvedName');

        return $resolvedName instanceof Name ? $resolvedName->toString() : $name->toString();
    }

    /**
     * @return array<int, Node>
     */
    private function parse(string $contents): array
    {
        try {
            $nodes = PhpParserFactory::make()->parse($contents);
        } catch (Error) {
            return [];
        }

        return NodeTraverserFactory::create()->traverse($nodes ?? []);
    }
}
