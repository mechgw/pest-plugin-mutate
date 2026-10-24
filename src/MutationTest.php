<?php

declare(strict_types=1);

namespace Pest\Mutate;

use ParaTest\Options;
use Pest\Mutate\Event\Facade;
use Pest\Mutate\Plugins\Mutate;
use Pest\Mutate\Repositories\TelemetryRepository;
use Pest\Mutate\Support\Configuration\Configuration;
use Pest\Mutate\Support\EnumCaseTestFinder;
use Pest\Mutate\Support\EnumCaseValueTimeout;
use Pest\Mutate\Support\MutationTestResult;
use Pest\Mutate\Support\TestRunOutcome;
use Pest\Plugins\Parallel;
use Pest\Support\Container;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class MutationTest
{
    private MutationTestResult $result = MutationTestResult::None;

    private ?float $start = null;

    private ?float $finish = null;

    private Process $process;

    /**
     * The JUnit report of the running process, when the mutation is in the value of an enum case.
     */
    private ?string $junitPath = null;

    /**
     * The process running all the originally selected tests, started when the candidate
     * tests of an enum case value do not kill the mutant.
     */
    private ?Process $fallbackProcess = null;

    /**
     * Why the tests of an enum case value could not tell whether the mutant is killed.
     */
    private ?string $inconclusiveReason = null;

    public function __construct(public readonly Mutation $mutation) {}

    public function getId(): string
    {
        return $this->mutation->id;
    }

    public function result(): MutationTestResult
    {
        return $this->result;
    }

    public function inconclusiveReason(): ?string
    {
        return $this->inconclusiveReason;
    }

    public function updateResult(MutationTestResult $result): void
    {
        $this->result = $result;
    }

    /**
     * @param  array<string, array<int, array<int, string>>>  $coveredLines
     * @param  array<int, string>  $originalArguments
     */
    public function start(array $coveredLines, Configuration $configuration, array $originalArguments, ?int $processId = null, ?EnumCaseTestFinder $enumCaseTestFinder = null): bool
    {
        // TODO: we should pass the tests to run in another way, maybe via cache, mutation or env variable
        $tests = [];
        foreach (range($this->mutation->startLine, $this->mutation->endLine) as $lineNumber) {
            foreach ($coveredLines[$this->mutation->file->getRealPath()][$lineNumber] ?? [] as $test) {
                $tests[] = $test;
            }
        }
        $filters = $this->filters($tests);

        // the value of an enum case may share its line with covered code that does not depend on it
        $candidateTests = $enumCaseTestFinder?->candidateTestIds($this->mutation);

        if ($filters === [] && $candidateTests === null) {
            $this->updateResult(MutationTestResult::Uncovered);

            Facade::instance()->emitter()->mutationUncovered($this);

            return false;
        }

        $envs = [
            Mutate::ENV_MUTATION_TESTING => $this->mutation->file->getRealPath(),
            Mutate::ENV_MUTATION_FILE => $this->mutation->modifiedSourcePath,
        ];

        if ($processId !== null) {
            $envs['PARATEST'] = '1';
            $envs[Options::ENV_KEY_TOKEN] = (string) $processId;
            $envs[Options::ENV_KEY_UNIQUE_TOKEN] = uniqid($processId.'_');
            $envs['LARAVEL_PARALLEL_TESTING'] = '1';
        }

        // remove coverage arguments from the original arguments
        $filteredArguments = array_filter($originalArguments, fn (string $argument): bool => ! str_starts_with($argument, '--coverage'));

        if ($candidateTests !== null) {
            $this->startEnumCaseValueVerification($filteredArguments, $envs, $this->filters([...$tests, ...$candidateTests]), $configuration);

            return true;
        }

        // TODO: filter arguments to remove unnecessary stuff (Teamcity, Coverage, etc.)
        $process = new Process(
            command: [
                ...$filteredArguments,
                '--bail',
                '--filter="'.implode('|', $filters).'"',
            ],
            env: $envs,
            timeout: $this->calculateTimeout(),
        );

        $this->start = microtime(true);

        $process->start();

        $this->process = $process;

        return true;
    }

    /**
     * @param  array<int, string>  $tests
     * @return array<int, string>
     */
    private function filters(array $tests): array
    {
        $filters = [];
        foreach ($tests as $test) {
            if (preg_match('/\\\\([a-zA-Z0-9]*)::(__pest_evaluable_)?([^#]*)"?/', $test, $matches) !== 1) {
                continue;
            }

            if ($matches[2] === '__pest_evaluable_') {
                $filters[] = $matches[1].'::(.*)'.str_replace(['__', '_'], ['.{1,2}', '.'], $matches[3]);
            } else {
                $filters[] = $matches[1].'::(.*)'.$matches[3];
            }
        }

        return array_values(array_unique($filters));
    }

    /**
     * No line covers the value of an enum case. The candidate tests referencing the case run
     * first, and all the originally selected tests run when none of them fails. Only a failed
     * test, read from the JUnit report, kills the mutant.
     *
     * @param  array<int, string>  $arguments
     * @param  array<string, string>  $envs
     * @param  array<int, string>  $candidateFilters
     */
    private function startEnumCaseValueVerification(array $arguments, array $envs, array $candidateFilters, Configuration $configuration): void
    {
        $this->junitPath = (string) tempnam(sys_get_temp_dir(), 'pest-mutate-junit-');

        $command = [...$arguments, '--bail', '--log-junit='.$this->junitPath];

        // all the originally selected tests run one after the other, even after a parallel initial test run
        $fullRunTimeout = EnumCaseValueTimeout::seconds(
            $this->calculateTimeout(),
            $configuration->enumTimeout,
            Parallel::isEnabled() ? $configuration->processes : 1,
        );

        if ($candidateFilters === []) {
            $this->process = new Process($command, env: $envs, timeout: $fullRunTimeout);
        } else {
            $this->process = new Process([...$command, '--filter="'.implode('|', $candidateFilters).'"'], env: $envs, timeout: $this->calculateTimeout());
            $this->fallbackProcess = new Process($command, env: $envs, timeout: $fullRunTimeout);
        }

        $this->start = microtime(true);

        $this->process->start();
    }

    private function startFallbackProcess(): bool
    {
        if (! $this->fallbackProcess instanceof Process) {
            return false;
        }

        $this->readJunitReport();

        $this->process = $this->fallbackProcess;
        $this->fallbackProcess = null;

        $this->process->start();

        return true;
    }

    private function finishEnumCaseValueVerification(): bool
    {
        $junit = $this->readJunitReport();
        $outcome = TestRunOutcome::fromJunit($this->process->getExitCode(), $junit);

        if ($outcome !== TestRunOutcome::Failed && $this->startFallbackProcess()) {
            return false;
        }

        $this->junitPath = null;

        if ($outcome === TestRunOutcome::Inconclusive) {
            $this->inconclusiveReason = sprintf(
                'Not verified: the tests ended with exit code %s and %s.',
                $this->process->getExitCode() ?? 'unknown',
                $junit === '' ? 'no JUnit report' : 'no reliable test failure in their JUnit report',
            );
        }

        $this->updateResult(match ($outcome) {
            TestRunOutcome::Failed => MutationTestResult::Tested,
            TestRunOutcome::Passed => MutationTestResult::Untested,
            // the tests did not run to completion, which is no evidence of a killed mutant
            TestRunOutcome::Inconclusive => MutationTestResult::Uncovered,
        });

        match ($outcome) {
            TestRunOutcome::Failed => Facade::instance()->emitter()->mutationTested($this),
            TestRunOutcome::Passed => Facade::instance()->emitter()->mutationEscaped($this),
            TestRunOutcome::Inconclusive => Facade::instance()->emitter()->mutationUncovered($this),
        };

        $this->finish = microtime(true);

        return true;
    }

    /**
     * All the originally selected tests did not finish within their time limit. Unlike the tests
     * covering a mutated line, they may simply be too slow: the timeout is no evidence of a killed mutant.
     */
    private function finishTimedOutEnumCaseValueVerification(): bool
    {
        $this->readJunitReport();
        $this->junitPath = null;

        $this->inconclusiveReason = sprintf(
            'Not verified: the tests did not finish within the time limit of %d s (--enum-timeout). A timeout is no evidence of a killed mutant.',
            (int) $this->process->getTimeout(),
        );

        $this->updateResult(MutationTestResult::Uncovered);

        Facade::instance()->emitter()->mutationUncovered($this);

        $this->finish = microtime(true);

        return true;
    }

    /**
     * Returns the contents of the JUnit report and deletes it, so that a next process starts without one.
     */
    private function readJunitReport(): string
    {
        if ($this->junitPath === null || ! is_file($this->junitPath)) {
            return '';
        }

        $junit = (string) file_get_contents($this->junitPath);

        unlink($this->junitPath);

        return $junit;
    }

    private function calculateTimeout(): int
    {
        /** @var TelemetryRepository $telemetryRepository */
        $telemetryRepository = Container::getInstance()->get(TelemetryRepository::class);

        $initialTestSuiteDuration = $telemetryRepository->getInitialTestSuiteDuration();

        return (int) ($initialTestSuiteDuration + max(5, $initialTestSuiteDuration * 0.2));
    }

    public function hasFinished(): bool
    {
        try {
            if ($this->process->isRunning()) {
                $this->process->checkTimeout();

                return false;
            }
        } catch (ProcessTimedOutException) {
            if ($this->startFallbackProcess()) {
                return false;
            }

            $this->readJunitReport();
            $this->junitPath = null;

            $this->updateResult(MutationTestResult::Timeout);

            Facade::instance()->emitter()->mutationTimedOut($this);

            $this->finish = microtime(true);

            return true;
        }

        if ($this->junitPath !== null) {
            return $this->finishEnumCaseValueVerification();
        }

        if ($this->process->isSuccessful()) {
            $this->updateResult(MutationTestResult::Untested);

            Facade::instance()->emitter()->mutationEscaped($this);

            $this->finish = microtime(true);

            return true;
        }

        $this->updateResult(MutationTestResult::Tested);

        Facade::instance()->emitter()->mutationTested($this);

        $this->finish = microtime(true);

        return true;
    }

    public function duration(): float
    {
        if ($this->start === null) {
            return 0;
        }
        if ($this->finish === null) {
            return 0;
        }

        return $this->finish - $this->start;
    }

    /**
     * @param  array<string, string>  $results
     */
    public function lastRunResult(array $results): MutationTestResult
    {
        return MutationTestResult::from($results[$this->getId()] ?? 'none');
    }
}
