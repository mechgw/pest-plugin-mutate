<?php

declare(strict_types=1);

namespace Pest\Mutate\Repositories;

class TelemetryRepository
{
    private float $initialTestSuiteDuration;

    /**
     * @var array<string, true>
     */
    private array $executedTests = [];

    public function initialTestSuiteDuration(float $duration): void
    {
        $this->initialTestSuiteDuration = $duration;
    }

    public function getInitialTestSuiteDuration(): float
    {
        return $this->initialTestSuiteDuration;
    }

    public function executedTest(string $id): void
    {
        $this->executedTests[$id] = true;
    }

    /**
     * @return array<int, string>
     */
    public function executedTests(): array
    {
        return array_keys($this->executedTests);
    }
}
