<?php

declare(strict_types=1);

use Pest\Mutate\Mutation;
use Pest\Mutate\Mutators\String\NotEmptyStringToEmpty;
use Pest\Mutate\Support\MutationGenerator;
use Symfony\Component\Finder\SplFileInfo;

it('keeps the position of the mutated node when cached', function (): void {
    [$mutation] = (new MutationGenerator)->generate(
        new SplFileInfo(__DIR__.'/../Fixtures/Enums/Single.php', '', ''),
        [NotEmptyStringToEmpty::class],
    );

    $cached = unserialize(serialize($mutation));

    expect($mutation->startFilePos)
        ->toBe(strpos((string) file_get_contents(__DIR__.'/../Fixtures/Enums/Single.php'), "'only'"))
        ->and($cached)->toBeInstanceOf(Mutation::class)
        ->and($cached->startFilePos)->toBe($mutation->startFilePos)
        ->and($cached->id)->toBe($mutation->id)
        ->and($cached->startLine)->toBe($mutation->startLine);
});

it('reads a mutation cached by a previous version without position', function (): void {
    $mutation = new ReflectionClass(Mutation::class)->newInstanceWithoutConstructor();

    $mutation->__unserialize([
        'file' => __DIR__.'/../Fixtures/Enums/Single.php',
        'id' => 'id',
        'mutator' => NotEmptyStringToEmpty::class,
        'start_line' => 9,
        'end_line' => 9,
        'diff' => '',
        'modified_source_path' => '',
    ]);

    expect($mutation->startFilePos)->toBeNull()
        ->and($mutation->startLine)->toBe(9);
});
