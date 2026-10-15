<?php

declare(strict_types=1);

namespace Pest\Mutate\Support;

use Closure;
use Pest\Mutate\Factories\NodeTraverserFactory;
use Pest\Mutate\Mutation;
use Pest\Support\Str;
use Pest\TestSuite;
use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;

/**
 * The value of a case of a string-backed enum is not an executable line, so line coverage
 * never tells which tests depend on it. This finds the executed tests that may observe such a
 * value: the tests covering a statement that references the case, and the tests whose own code
 * references the case in their test file.
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
     * The hooks run around the tests of their file, or of their describe block.
     */
    private const array PEST_HOOKS = ['beforeeach', 'aftereach', 'beforeall', 'afterall'];

    private const array PHPUNIT_HOOK_METHODS = ['setup', 'teardown', 'setupbeforeclass', 'teardownafterclass', 'assertpreconditions', 'assertpostconditions'];

    private const array PHPUNIT_HOOK_ATTRIBUTES = ['before', 'after', 'beforeclass', 'afterclass', 'precondition', 'postcondition'];

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
     * @param  array<string, array<int, array{ids: array<int, string>, startLine: int, endLine: int}>>  $testCases  the tests declared by each test file having executed tests, with the lines of their code and the ids of their executions, if any
     */
    public function __construct(
        private readonly array $coveredLines,
        private readonly array $testCases,
    ) {}

    /**
     * @param  array<string, array<int, array<int, string>>>  $coveredLines
     * @param  array<int, string>  $executedTestIds
     */
    public static function fromExecutedTests(array $coveredLines, array $executedTestIds): self
    {
        $executions = [];

        foreach ($executedTestIds as $testId) {
            // the id of a test run with a dataset ends with the name of the data set
            if (preg_match('/^([^:\s]+)::([a-zA-Z0-9_\x80-\xff]+)/', $testId, $matches) === 1) {
                $executions[$matches[1]][$matches[2]][] = $testId;
            }
        }

        $testCases = [];

        foreach ($executions as $class => $methods) {
            if (! class_exists($class, false)) {
                continue;
            }

            foreach (self::testsOfClass(new ReflectionClass($class), $methods) as $file => $tests) {
                $testCases[$file] = [...$testCases[$file] ?? [], ...$tests];
            }
        }

        return new self($coveredLines, $testCases);
    }

    /**
     * Returns the tests of the given class, executed or not, with the lines of their code.
     *
     * @param  ReflectionClass<object>  $class
     * @param  array<string, array<int, string>>  $executions
     * @return array<string, array<int, array{ids: array<int, string>, startLine: int, endLine: int}>>
     */
    private static function testsOfClass(ReflectionClass $class, array $executions): array
    {
        $code = [];

        // the class of a Pest test file is evaluated: the code of each test is a closure
        if ($class->hasProperty('__filename') && $class->getProperty('__filename')->isStatic()) {
            $file = $class->getStaticPropertyValue('__filename');
            $factory = is_string($file) ? TestSuite::getInstance()->tests->get($file) : null;

            foreach ($factory->methods ?? [] as $method) {
                if ($method->description !== null && $method->closure instanceof Closure) {
                    $code[Str::evaluable($method->description)] = new ReflectionFunction($method->closure);
                }
            }
        } else {
            foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if (isset($executions[$method->getName()]) || str_starts_with($method->getName(), 'test') || $method->getAttributes(Test::class) !== []) {
                    $code[$method->getName()] = $method;
                }
            }
        }

        $tests = [];

        foreach ($code as $name => $function) {
            $file = $function->getFileName();
            $startLine = $function->getStartLine();
            $endLine = $function->getEndLine();

            if ($file !== false && $startLine !== false && $endLine !== false) {
                $tests[$file][] = ['ids' => $executions[$name] ?? [], 'startLine' => $startLine, 'endLine' => $endLine];
            }
        }

        return $tests;
    }

    /**
     * Returns the ids of the tests to run first to kill the given mutation, or null when the
     * mutation is not in the value of a case of a string-backed enum.
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
     * file that are referenced by a covered statement or by the code of an executed test.
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

        foreach (array_unique([...array_keys($this->coveredLines), ...array_keys($this->testCases)]) as $file) {
            $contents = is_file($file) ? file_get_contents($file) : false;

            // a reference, or the import it relies on, names the enum
            if ($contents === false || ! str_contains($contents, $shortName)) {
                continue;
            }

            $nodes = $this->parse($contents);
            $testCases = $this->widenToStatements($nodes, $this->testCases[$file] ?? []);

            foreach ($this->references($nodes, $enum) as [$case, $stmt, $reference]) {
                foreach ($this->coveredLines[$file] ?? [] as $line => $ids) {
                    if ($line >= $stmt->getStartLine() && $line <= $stmt->getEndLine()) {
                        $testIds[$case] = [...$testIds[$case] ?? [], ...$ids];
                    }
                }

                $testIds[$case] = [...$testIds[$case] ?? [], ...$this->executionsReaching($testCases, $stmt, $reference)];
            }
        }

        return array_map(fn (array $ids): array => array_values(array_unique($ids)), $testIds);
    }

    /**
     * Widens the lines of the code of each test to the innermost statement containing it, so
     * that the statement declaring a test, with its dataset, belongs to the test.
     *
     * @param  array<int, Node>  $nodes
     * @param  array<int, array{ids: array<int, string>, startLine: int, endLine: int}>  $testCases
     * @return array<int, array{ids: array<int, string>, startLine: int, endLine: int}>
     */
    private function widenToStatements(array $nodes, array $testCases): array
    {
        $stmts = (new NodeFinder)->findInstanceOf($nodes, Stmt::class);

        foreach ($testCases as $index => $testCase) {
            $innermost = null;

            foreach ($stmts as $stmt) {
                if ($stmt->getStartLine() > $testCase['startLine'] || $stmt->getEndLine() < $testCase['endLine']) {
                    continue;
                }

                if (! $innermost instanceof Stmt || $stmt->getEndLine() - $stmt->getStartLine() < $innermost->getEndLine() - $innermost->getStartLine()) {
                    $innermost = $stmt;
                }
            }

            if ($innermost instanceof Stmt) {
                $testCases[$index]['startLine'] = $innermost->getStartLine();
                $testCases[$index]['endLine'] = $innermost->getEndLine();
            }
        }

        return $testCases;
    }

    /**
     * Returns the ids of the executions of the tests whose code contains the given statement, or
     * of the executed tests around which the hook containing it runs. Any other code of a test
     * file, like a helper, may run for none of the executed tests: it reaches none.
     *
     * @param  array<int, array{ids: array<int, string>, startLine: int, endLine: int}>  $testCases
     * @return array<int, string>
     */
    private function executionsReaching(array $testCases, Stmt $stmt, Node $reference): array
    {
        $containing = array_filter($testCases, fn (array $testCase): bool => $stmt->getStartLine() >= $testCase['startLine'] && $stmt->getEndLine() <= $testCase['endLine']);

        if ($containing === []) {
            $scope = $this->hookScope($reference);

            $containing = $scope === null ? [] : array_filter($testCases, fn (array $testCase): bool => $testCase['startLine'] >= $scope[0] && $testCase['endLine'] <= $scope[1]);
        }

        return array_merge(...array_column($containing, 'ids'));
    }

    /**
     * Returns the lines of the tests around which the hook containing the given node runs: the
     * tests of its describe block, of its class, or of its file. Null when it is not in a hook.
     *
     * @return array{0: int, 1: int}|null
     */
    private function hookScope(Node $node): ?array
    {
        $hook = null;

        for ($parent = $node->getAttribute('parent'); $parent instanceof Node; $parent = $parent->getAttribute('parent')) {
            if (! $hook instanceof Node) {
                if ($this->isHook($parent)) {
                    $hook = $parent;
                }

                continue;
            }

            if ($parent instanceof ClassLike || ($parent instanceof FuncCall && $this->callName($parent) === 'describe')) {
                return [$parent->getStartLine(), $parent->getEndLine()];
            }
        }

        return $hook instanceof Node ? [0, PHP_INT_MAX] : null;
    }

    private function isHook(Node $node): bool
    {
        if ($node instanceof CallLike) {
            return in_array($this->callName($node), self::PEST_HOOKS, true);
        }

        if (! $node instanceof ClassMethod) {
            return false;
        }

        if (in_array($node->name->toLowerString(), self::PHPUNIT_HOOK_METHODS, true)) {
            return true;
        }

        foreach ($node->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attribute) {
                if (in_array(strtolower($attribute->name->getLast()), self::PHPUNIT_HOOK_ATTRIBUTES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function callName(CallLike $call): ?string
    {
        $name = match (true) {
            $call instanceof FuncCall => $call->name,
            $call instanceof MethodCall, $call instanceof StaticCall => $call->name,
            default => null,
        };

        return $name instanceof Name || $name instanceof Identifier ? $name->toLowerString() : null;
    }

    /**
     * Returns the referenced case of the given enum, or "*" for any case, the innermost
     * statement containing the reference, and the reference.
     *
     * @param  array<int, Node>  $nodes
     * @return array<int, array{0: string, 1: Stmt, 2: Node}>
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
                $references[] = [$name, $stmt, $node];
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
