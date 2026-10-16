<?php

declare(strict_types=1);

use Pest\Mutate\Support\ExecutedTestsRecord;

/**
 * A stream that writes one byte less than asked, like a full disk.
 */
final class ShortWriteStream
{
    public mixed $context;

    public function stream_open(): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return strlen($data) - 1;
    }

    public function stream_lock(): bool
    {
        return true;
    }
}

it('reads the recorded tests', function (): void {
    $file = (string) tempnam(sys_get_temp_dir(), 'pest-mutate-record');

    ExecutedTestsRecord::append($file, 'P\\Tests\\FooTest::__pest_evaluable_it_works');
    ExecutedTestsRecord::append($file, 'P\\Tests\\FooTest::__pest_evaluable_it_works_with#(1, true)');

    expect(ExecutedTestsRecord::parse((string) file_get_contents($file)))->toBe([
        'P\\Tests\\FooTest::__pest_evaluable_it_works',
        'P\\Tests\\FooTest::__pest_evaluable_it_works_with#(1, true)',
    ])->and(ExecutedTestsRecord::parse(''))->toBe([]);

    unlink($file);
});

it('rejects an incomplete or corrupted record', function (string $contents): void {
    expect(ExecutedTestsRecord::parse($contents))->toBeNull();
})->with([
    'last line cut' => ["P\\Tests\\FooTest::a\t".hash('crc32b', 'P\\Tests\\FooTest::a')."\nP\\Tests\\FooTest::b"],
    'id cut before its checksum' => ["P\\Tests\\FooTest::a\t".hash('crc32b', 'P\\Tests\\FooTest::ab')."\n"],
    'checksum cut' => ["P\\Tests\\FooTest::a\t".substr(hash('crc32b', 'P\\Tests\\FooTest::a'), 0, 4)."\n"],
    'line without checksum' => ["P\\Tests\\FooTest::a\n"],
    'empty line' => ["\n"],
]);

it('fails when a test is not completely written', function (): void {
    stream_wrapper_register('pest-mutate-short', ShortWriteStream::class);
    // PHP warns about the bytes that were not written, as on a full disk
    set_error_handler(fn (): bool => true, E_WARNING);

    try {
        expect(fn () => ExecutedTestsRecord::append('pest-mutate-short://record', 'P\\Tests\\FooTest::a'))
            ->toThrow(RuntimeException::class, 'Unable to record the executed test [P\\Tests\\FooTest::a]');
    } finally {
        restore_error_handler();
        stream_wrapper_unregister('pest-mutate-short');
    }
});
