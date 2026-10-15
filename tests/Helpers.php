<?php

declare(strict_types=1);

use Pest\Mutate\Factories\NodeTraverserFactory;
use Pest\Mutate\Support\PhpParserFactory;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;
use Symfony\Component\Process\Process;

/**
 * Runs the mutation test suite of the given fixture, and returns its output
 * without the bits that change from machine to machine.
 */
function mutationTestOutput(string $fixture): string
{
    $process = new Process(
        ['php', 'vendor/bin/pest', 'tests/.tests/'.$fixture, '--mutate'],
        dirname(__DIR__),
        ['COLUMNS' => 80, 'XDEBUG_MODE' => 'coverage', 'PEST_PLUGIN_INTERNAL_TEST_SUITE' => 1],
    );

    $process->run();

    return (string) preg_replace([
        '#\x1b[[][^A-Za-z]*[A-Za-z]#', // colors
        '/ID: [0-9a-f]+/', // mutation ids, they are based on the absolute path of the file
        '/Duration: +.*/', // durations
    ], [
        '',
        'ID: xxx',
        'Duration: xxx',
    ], $process->getOutput());
}

function mutateCode(string $mutator, string $code): string
{
    $stmts = PhpParserFactory::make()->parse($code);

    $mutationCount = 0;

    $traverser = NodeTraverserFactory::create();
    $traverser->addVisitor(new class($mutator, function () use (&$mutationCount): void {
        $mutationCount++;
    }) extends NodeVisitorAbstract
    {
        public function __construct(
            private readonly string $mutator,
            private readonly Closure $incrementMutationCount,
        ) {}

        public function leaveNode(Node $node): mixed
        {
            if ($this->mutator::can($node)) {
                ($this->incrementMutationCount)();

                return $this->mutator::mutate($node);
            }

            return null;
        }
    });

    $newStmts = $traverser->traverse($stmts);

    if ($mutationCount === 0) {
        throw new Exception('No mutation performed');
    }

    $prettyPrinter = new Standard;

    return $prettyPrinter->prettyPrintFile($newStmts);
}

/**
 * Runs the mutation tests of the given scenario of the enum fixtures, mutating the
 * non-empty strings of the given class only, and returns their output.
 *
 * The classes are given with --class: mutates() would make Pest write a lock file
 * shared with this test suite, which restricts it to the mutated tests in parallel.
 *
 * @param  array<int, string>  $arguments
 * @param  array<string, string|false>  $env
 */
function enumMutationTestOutput(string $scenario, string $class, array $arguments = [], array $env = []): string
{
    $process = enumMutationTestProcess($scenario, $class, $arguments, $env);

    return $process->getOutput().$process->getErrorOutput();
}

/**
 * Runs the mutation tests of the given scenario of the enum fixtures, and returns the finished process.
 *
 * @param  array<int, string>  $arguments
 * @param  array<string, string|false>  $env
 */
function enumMutationTestProcess(string $scenario, string $class, array $arguments = [], array $env = []): Process
{
    $process = new Process(
        [
            PHP_BINARY,
            'vendor/bin/pest',
            '--configuration='.enumFixturesConfiguration(),
            'tests/.tests/Enums/'.$scenario,
            '--mutate',
            '--mutator=StringNotEmptyStringToEmpty',
            '--class='.$class,
            '--colors=never',
            ...$arguments,
        ],
        dirname(__DIR__),
        [
            'COLUMNS' => '120',
            'XDEBUG_MODE' => 'coverage',
            'PEST_PLUGIN_INTERNAL_TEST_SUITE' => '1',
            'PEST_MUTATE_GENERATED_ENUMS' => dirname(enumFixturesConfiguration()),
            // when this test suite runs in parallel, the mutation tests must not run as a worker
            'PARATEST' => false,
            ...$env,
        ],
        timeout: 120,
    );

    $process->run();

    return $process;
}

/**
 * Returns the summary of the mutations of the given scenario of the enum fixtures.
 *
 * @param  array<int, string>  $arguments
 * @param  array<string, string|false>  $env
 */
function enumMutationTestSummary(string $scenario, string $class, array $arguments = [], array $env = []): string
{
    $output = enumMutationTestOutput($scenario, $class, $arguments, $env);

    if (preg_match('/Mutations: *(.+)/', $output, $matches) !== 1) {
        throw new Exception($output);
    }

    return trim($matches[1]);
}

/**
 * Writes the PHPUnit configuration of the enum fixtures, and returns its path. The code style
 * does not allow two statements on one line, so the enum that needs it is written at runtime,
 * next to the configuration that covers it. Like in most projects, the tests are not covered.
 */
function enumFixturesConfiguration(): string
{
    $root = dirname(__DIR__);
    // inside the repository: when the covered files only share the root directory,
    // php-code-coverage reports them without their leading separator
    $directory = $root.DIRECTORY_SEPARATOR.'.temp'.DIRECTORY_SEPARATOR.'enum-fixtures';

    if (! is_dir($directory)) {
        mkdir($directory);
    }

    file_put_contents($directory.DIRECTORY_SEPARATOR.'SameLine.php', <<<'PHP'
        <?php

        namespace Tests\Fixtures\Enums\Generated;

        enum SameLine: string
        {
            case First = 'FIRST'; case Second = 'SECOND';
        }
        PHP);

    file_put_contents($directory.DIRECTORY_SEPARATOR.'phpunit.xml', <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <phpunit bootstrap="{$root}/vendor/autoload.php" cacheDirectory="{$root}/.phpunit.cache" colors="true">
            <testsuites>
                <testsuite name="enums">
                    <directory suffix="Test.php">{$root}/tests/.tests/Enums</directory>
                </testsuite>
            </testsuites>
            <source>
                <include>
                    <directory suffix=".php">{$root}/tests/Fixtures/Enums</directory>
                    <directory suffix=".php">{$directory}</directory>
                </include>
            </source>
        </phpunit>
        XML);

    return $directory.DIRECTORY_SEPARATOR.'phpunit.xml';
}
