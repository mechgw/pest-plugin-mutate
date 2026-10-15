<?php

declare(strict_types=1);

namespace Pest\Mutate\Tester;

use Composer\InstalledVersions;
use Pest\Mutate\Contracts\MutationTestRunner as MutationTestRunnerContract;
use Pest\Mutate\Contracts\Printer;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\Mutation;
use Pest\Mutate\MutationSuite;
use Pest\Mutate\MutationTest;
use Pest\Mutate\Plugins\Mutate;
use Pest\Mutate\Repositories\ConfigurationRepository;
use Pest\Mutate\Repositories\TelemetryRepository;
use Pest\Mutate\Support\Configuration\Configuration;
use Pest\Mutate\Support\EnumCaseTestFinder;
use Pest\Mutate\Support\FileFinder;
use Pest\Mutate\Support\MutationGenerator;
use Pest\Plugins\Parallel;
use Pest\Support\Container;
use Pest\Support\Coverage;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Data\ProcessedCodeCoverageData;

class MutationTestRunner implements MutationTestRunnerContract
{
    private bool $enabled = false;

    private bool $codeCoverageRequested = false;

    private bool $stop = false;

    /**
     * @var array<int, string>
     */
    private array $originalArguments;

    /**
     * @var array<int, ?MutationTest>
     */
    private array $runningTests;

    private float $startTime;

    private ?EnumCaseTestFinder $enumCaseTestFinder = null;

    public static function fake(): MutationTestRunnerFake
    {
        $fake = new MutationTestRunnerFake;

        Container::getInstance()->add(MutationTestRunnerContract::class, $fake);

        return $fake;
    }

    public function stopExecution(): void
    {
        $this->stop = true;
    }

    /**
     * @param  array<int, string>  $arguments
     */
    public function setOriginalArguments(array $arguments): void
    {
        $this->originalArguments = $arguments;
    }

    public function enable(): void
    {
        if (getenv(Mutate::ENV_MUTATION_TESTING) !== false) {
            return;
        }

        $this->enabled = true;

        if (Parallel::isWorker()) {
            return;
        }

        $file = getenv(Mutate::ENV_EXECUTED_TESTS_FILE);

        // inherited by the workers of a parallel initial test run, which only inherit the
        // variables also known to $_SERVER
        if ($file === false) {
            $file = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('pest-mutate-executed-tests-', true);

            putenv(Mutate::ENV_EXECUTED_TESTS_FILE.'='.$file);
            $_SERVER[Mutate::ENV_EXECUTED_TESTS_FILE] = $_ENV[Mutate::ENV_EXECUTED_TESTS_FILE] = $file;

            // the file is left over when the mutations are not tested, e.g. when a test fails
            register_shutdown_function(static fn (): bool => is_file($file) && unlink($file));
        }

        // created before the tests run, so that a missing file means a failure, not a run
        // without tests, and a file that cannot be written stops the run before any test
        if (! is_file($file) && (! is_dir(dirname($file)) || ! touch($file))) {
            throw new RuntimeException(sprintf('Unable to create [%s], in which the parallel workers record the tests they execute.', $file));
        }
    }

    public function isEnabled(): bool
    {
        if (str_starts_with((string) $this->enabled, ConfigurationRepository::FAKE)) {
            return false;
        }

        return $this->enabled;
    }

    public function doNotDisableCodeCoverage(): void
    {
        $this->codeCoverageRequested = true;
    }

    public function isCodeCoverageRequested(): bool
    {
        return $this->codeCoverageRequested;
    }

    public function run(): int
    {
        Container::getInstance()->get(TelemetryRepository::class)->initialTestSuiteDuration( // @phpstan-ignore-line
            microtime(true) - $this->startTime
        );

        if (! Coverage::isAvailable() || ! file_exists($reportPath = Coverage::getPath())) {
            Container::getInstance()->get(Printer::class)->reportError('No coverage report found, aborting mutation testing.'); // @phpstan-ignore-line

            return 1;
        }

        $this->clearCacheIfPluginVersionChanged();

        $mutationSuite = MutationSuite::instance();

        Facade::instance()->emitter()->startMutationGeneration($mutationSuite);

        /** @var CodeCoverage|array{basePath: string, codeCoverage: ProcessedCodeCoverageData} $loadedCoverage */
        $loadedCoverage = require $reportPath;

        unlink($reportPath);

        if ($loadedCoverage instanceof CodeCoverage) {
            $coverageData = $loadedCoverage->getData();
        } else {
            // since phpunit/php-code-coverage 14, `--coverage-php` writes an array instead of a
            // serialized CodeCoverage, and its file keys are relative to `basePath`
            $coverageData = $loadedCoverage['codeCoverage'];
            $basePath = $loadedCoverage['basePath'];

            if ($basePath !== '') {
                foreach ($coverageData->coveredFiles() as $relativePath) {
                    $coverageData->renameFile($relativePath, $basePath.DIRECTORY_SEPARATOR.$relativePath);
                }
            }
        }

        $coveredLines = $this->coveredLines($coverageData);

        /** @var TelemetryRepository $telemetryRepository */
        $telemetryRepository = Container::getInstance()->get(TelemetryRepository::class);

        $executedTests = $telemetryRepository->executedTests();

        if (Parallel::isEnabled()) {
            $testsExecutedByWorkers = $this->testsExecutedByWorkers();

            if ($testsExecutedByWorkers === null) {
                Container::getInstance()->get(Printer::class)->reportError('Unable to read the tests executed by the parallel workers, aborting mutation testing.'); // @phpstan-ignore-line

                return 1;
            }

            $executedTests = [...$executedTests, ...$testsExecutedByWorkers];
        }

        // every test of the code coverage was executed: one missing reveals an incomplete record
        $unrecordedTests = array_diff($coverageData->testIds(), $executedTests);

        if ($unrecordedTests !== []) {
            Container::getInstance()->get(Printer::class)->reportError(sprintf('%d tests of the code coverage were not recorded as executed, aborting mutation testing.', count($unrecordedTests))); // @phpstan-ignore-line

            return 1;
        }

        $this->enumCaseTestFinder = $enumCaseTestFinder = EnumCaseTestFinder::fromExecutedTests(
            $coveredLines,
            array_values(array_unique($executedTests)),
        );

        $files = FileFinder::files($this->getConfiguration()->paths, $this->getConfiguration()->pathsToIgnore);

        /** @var MutationGenerator $generator */
        $generator = Container::getInstance()->get(MutationGenerator::class);

        foreach ($files as $file) {
            $linesToMutate = [];
            $coveredFileLines = [];
            $referencedCaseValues = [];

            if ($this->getConfiguration()->coveredOnly) {
                $coveredFileLines = array_keys($coveredLines[$file->getRealPath()] ?? []);

                // the value of an enum case is never covered, but it is used when the case is referenced
                $referencedCaseValues = $enumCaseTestFinder->referencedCaseValues((string) $file->getRealPath());

                if ($coveredFileLines === [] && $referencedCaseValues === []) {
                    continue;
                }

                $linesToMutate = $coveredFileLines;

                foreach ($referencedCaseValues as $caseValue) {
                    array_push($linesToMutate, ...range($caseValue['startLine'], $caseValue['endLine']));
                }
            }

            foreach ($generator->generate(
                file: $file,
                mutators: $this->getConfiguration()->mutators,
                linesToMutate: array_values(array_unique($linesToMutate)),
                classesToMutate: $this->getConfiguration()->everything ? [] : $this->getConfiguration()->classes,
            ) as $mutation) {
                if ($this->getConfiguration()->mutationId !== null && $mutation->id !== $this->getConfiguration()->mutationId) {
                    continue;
                }

                if ($referencedCaseValues !== [] && ! $this->isCoveredOrInCaseValue($mutation, $coveredFileLines, $referencedCaseValues)) {
                    continue;
                }

                $mutationSuite->repository->add($mutation);
            }
        }

        Facade::instance()->emitter()->finishMutationGeneration($mutationSuite);

        if ($this->getConfiguration()->retry) {
            $mutationSuite->repository->sortByEscapedFirst();
        }

        Facade::instance()->emitter()->startMutationSuite($mutationSuite);

        if ($this->getConfiguration()->parallel) {
            $this->runTestsInParallel(
                mutationSuite: $mutationSuite,
                coveredLines: $coveredLines,
                processes: $this->getConfiguration()->processes,
            );
        } else {
            $this->runTests(
                mutationSuite: $mutationSuite,
                coveredLines: $coveredLines,
            );
        }

        $mutationSuite->repository->saveResults();

        Facade::instance()->emitter()->finishMutationSuite($mutationSuite);

        return $this->isMinScoreIsReached($mutationSuite) ? 0 : 1;
    }

    /**
     * A line holding the value of a referenced enum case may hold other code, or the value
     * of another case, that must not be mutated unless covered.
     *
     * @param  array<int, int>  $coveredFileLines
     * @param  array<int, array{startFilePos: int, endFilePos: int, startLine: int, endLine: int}>  $caseValues
     */
    private function isCoveredOrInCaseValue(Mutation $mutation, array $coveredFileLines, array $caseValues): bool
    {
        if (array_intersect(range($mutation->startLine, $mutation->endLine), $coveredFileLines) !== []) {
            return true;
        }

        return array_any($caseValues, fn (array $caseValue): bool => $mutation->startFilePos !== null && $mutation->startFilePos >= $caseValue['startFilePos'] && $mutation->startFilePos <= $caseValue['endFilePos']);
    }

    /**
     * Returns the tests executed by the workers of a parallel initial test run, or null when
     * they cannot be read.
     *
     * @return array<int, string>|null
     */
    private function testsExecutedByWorkers(): ?array
    {
        $file = getenv(Mutate::ENV_EXECUTED_TESTS_FILE);

        if (! is_string($file) || ! is_file($file)) {
            return null;
        }

        $tests = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        unlink($file);

        return $tests === false ? null : $tests;
    }

    /**
     * Maps every covered line to the ids of the test cases that executed it.
     *
     * @return array<string, array<int, array<int, string>>>
     */
    private function coveredLines(ProcessedCodeCoverageData $coverageData): array
    {
        // since phpunit/php-code-coverage 14.3, test case ids are interned: the hit map of a
        // line is keyed by test index, and `testIds()` resolves an index back to its id
        $testIds = $coverageData->testIds();

        $coveredLines = [];

        foreach ($coverageData->lineCoverage() as $file => $lines) {
            foreach ($lines as $line => $tests) {
                $tests = array_values(array_intersect_key($testIds, $tests ?? []));

                if ($tests === []) {
                    continue;
                }

                $coveredLines[$file][$line] = $tests;
            }
        }

        return $coveredLines;
    }

    private function getConfiguration(): Configuration
    {
        return Container::getInstance()->get(ConfigurationRepository::class)->mergedConfiguration(); // @phpstan-ignore-line
    }

    private function isMinScoreIsReached(MutationSuite $mutationSuite): bool
    {
        /** @var ConfigurationRepository $configurationRepository */
        $configurationRepository = Container::getInstance()->get(ConfigurationRepository::class);

        $configuration = $configurationRepository->mergedConfiguration();

        $minScore = $configuration->minScore;

        if ($minScore === null) {
            return true;
        }

        if ($mutationSuite->repository->count() === 0 && $configuration->ignoreMinScoreOnZeroMutations) {
            return true;
        }

        $score = $mutationSuite->score();
        if ($score >= $minScore) {
            return true;
        }

        /** @var Printer $printer */
        $printer = Container::getInstance()->get(Printer::class);

        $printer->reportScoreNotReached($score, $minScore);

        return false;
    }

    /**
     * @param  array<string, array<int, array<int, string>>>  $coveredLines
     */
    private function runTestsInParallel(MutationSuite $mutationSuite, array $coveredLines, int $processes): void
    {
        $tests = [];
        foreach ($mutationSuite->repository->all() as $testCollection) {
            foreach ($testCollection->tests() as $test) {
                $tests[] = $test;
            }
        }

        $this->runningTests = array_fill(1, $processes, null);

        foreach ($tests as $test) {
            if ($this->stop) {
                break;
            }

            while (count(array_filter($this->runningTests, fn (?MutationTest $process): bool => $process instanceof MutationTest)) >= $processes) {
                if ($this->checkRunningTestsHaveFinished()) {
                    continue;
                }

                usleep(1000); // @pest-arch-ignore-line
            }

            $processId = (int) array_key_first(array_filter($this->runningTests, fn (?MutationTest $process): bool => ! $process instanceof MutationTest));
            if ($test->start($coveredLines, $this->getConfiguration(), $this->originalArguments, $processId, $this->enumCaseTestFinder)) {
                $this->runningTests[$processId] = $test;
            }
        }

        while (! $this->stop && array_filter($this->runningTests, fn (?MutationTest $process): bool => $process instanceof MutationTest) !== []) {
            $this->checkRunningTestsHaveFinished();
        }
    }

    /**
     * @param  array<string, array<int, array<int, string>>>  $coveredLines
     */
    private function runTests(MutationSuite $mutationSuite, array $coveredLines): void
    {
        foreach ($mutationSuite->repository->all() as $testCollection) {
            Facade::instance()->emitter()->startTestCollection($testCollection);

            foreach ($testCollection->tests() as $test) {
                if ($this->stop) {
                    break 2;
                }

                if ($test->start($coveredLines, $this->getConfiguration(), $this->originalArguments, enumCaseTestFinder: $this->enumCaseTestFinder)) {
                    while (! $test->hasFinished()) {
                        usleep(1000);
                    }
                }
            }
        }
    }

    private function checkRunningTestsHaveFinished(): bool
    {
        foreach ($this->runningTests as $index => $runningTest) {
            if (! $runningTest instanceof MutationTest) {
                continue;
            }

            if ($runningTest->hasFinished()) {
                $this->runningTests[$index] = null;

                return true;
            }
        }

        return false;
    }

    private function clearCacheIfPluginVersionChanged(): void
    {
        $cache = Container::getInstance()->get(CacheInterface::class);

        $pluginVersion = InstalledVersions::getVersion('pestphp/pest-plugin-mutate');

        /** @var ?string $previousVersion */
        $previousVersion = $cache->get('mutation-plugin-version'); // @phpstan-ignore-line

        if ($previousVersion === null || $previousVersion !== $pluginVersion) {
            $cache->clear(); // @phpstan-ignore-line
        }

        $cache->set('mutation-plugin-version', $pluginVersion); // @phpstan-ignore-line
    }

    public function setStartTime(float $startTime): void
    {
        $this->startTime = $startTime;
    }
}
