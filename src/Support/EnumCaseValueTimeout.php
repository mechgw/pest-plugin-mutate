<?php

declare(strict_types=1);

namespace Pest\Mutate\Support;

/**
 * The time limit of the run of all the originally selected tests verifying the value of an enum case.
 *
 * Unlike the tests covering a mutated line, these tests run one after the other, even when the
 * initial test run was parallel. Without a configured limit, the limit of the initial test run is
 * multiplied by its number of processes: an estimate of a serial run, not a guarantee.
 */
final class EnumCaseValueTimeout
{
    public static function seconds(int $initialRunTimeout, ?int $configured, int $initialRunProcesses): int
    {
        return $configured ?? $initialRunTimeout * max(1, $initialRunProcesses);
    }
}
