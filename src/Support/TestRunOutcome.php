<?php

declare(strict_types=1);

namespace Pest\Mutate\Support;

use DOMDocument;
use DOMXPath;

/**
 * The outcome of a test run, read from its JUnit report.
 *
 * A non-zero exit code alone is no evidence of a failed test: it is also the result of a
 * process that could not be started, a fatal error, or a test runner warning.
 */
enum TestRunOutcome
{
    case Passed;
    case Failed;
    case Inconclusive;

    public static function fromJunit(?int $exitCode, string $junit): self
    {
        $document = new DOMDocument;

        $useInternalErrors = libxml_use_internal_errors(true);
        $loaded = $junit !== '' && $document->loadXML($junit, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($useInternalErrors);

        if (! $loaded) {
            return self::Inconclusive;
        }

        $xpath = new DOMXPath($document);

        $tests = $xpath->query('//testcase');
        $defects = $xpath->query('//testcase/failure | //testcase/error');

        if ($tests === false || $defects === false) {
            return self::Inconclusive;
        }

        return match (true) {
            // terminated by a signal, or the status could not be read
            $exitCode === null => self::Inconclusive,
            $exitCode === 0 => $tests->length > 0 && $defects->length === 0 ? self::Passed : self::Inconclusive,
            default => $defects->length > 0 ? self::Failed : self::Inconclusive,
        };
    }
}
