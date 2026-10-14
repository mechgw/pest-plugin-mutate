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
 * non-empty strings of the given class only, and returns the summary of the mutations.
 *
 * The classes are given with --class: mutates() would make Pest write a lock file
 * shared with this test suite, which restricts it to the mutated tests in parallel.
 *
 * @param  array<int, string>  $arguments
 * @param  array<string, string|false>  $env
 */
function enumMutationTestSummary(string $scenario, string $class, array $arguments = [], array $env = []): string
{
    $process = new Process(
        [
            PHP_BINARY,
            'vendor/bin/pest',
            '--configuration=tests/.tests/Enums/phpunit.xml',
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
            'PEST_MUTATE_SAME_LINE_ENUM' => sameLineEnum(),
            // when this test suite runs in parallel, the mutation tests must not run as a worker
            'PARATEST' => false,
            ...$env,
        ],
        timeout: 120,
    );

    $process->run();

    if (preg_match('/Mutations: *(.+)/', $process->getOutput(), $matches) !== 1) {
        throw new Exception($process->getOutput().$process->getErrorOutput());
    }

    return trim($matches[1]);
}

/**
 * Two cases on one line are not allowed by the code style, so this enum is written at runtime.
 */
function sameLineEnum(): string
{
    $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pest-mutate-same-line-enum'.DIRECTORY_SEPARATOR.'SameLine.php';

    if (! is_dir(dirname($file))) {
        mkdir(dirname($file));
    }

    file_put_contents($file, <<<'PHP'
        <?php

        namespace Tests\Fixtures\Enums\Generated;

        enum SameLine: string
        {
            case First = 'FIRST'; case Second = 'SECOND';
        }
        PHP);

    return $file;
}
