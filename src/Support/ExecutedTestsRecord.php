<?php

declare(strict_types=1);

namespace Pest\Mutate\Support;

use RuntimeException;

/**
 * The file in which the workers of a parallel initial test run record the tests they execute.
 *
 * Each line holds a test id and its checksum, so that a record cut by a short or interrupted
 * write is detected instead of silently leaving out a test.
 */
final class ExecutedTestsRecord
{
    public static function append(string $file, string $testId): void
    {
        $line = $testId."\t".hash('crc32b', $testId)."\n";

        if (file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new RuntimeException(sprintf('Unable to record the executed test [%s] in [%s].', $testId, $file));
        }
    }

    /**
     * Returns the recorded test ids, or null when a line is incomplete or corrupted.
     *
     * @return array<int, string>|null
     */
    public static function parse(string $contents): ?array
    {
        if ($contents === '') {
            return [];
        }

        if (! str_ends_with($contents, "\n")) {
            return null;
        }

        $testIds = [];

        foreach (explode("\n", substr($contents, 0, -1)) as $line) {
            if (preg_match('/^(.+)\t([0-9a-f]{8})$/', $line, $matches) !== 1 || hash('crc32b', $matches[1]) !== $matches[2]) {
                return null;
            }

            $testIds[] = $matches[1];
        }

        return $testIds;
    }
}
