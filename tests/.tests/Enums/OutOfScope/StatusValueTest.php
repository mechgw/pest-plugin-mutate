<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Status;

require_once __DIR__.'/helpers.php';

// a closure held in a variable: its references are not followed
$publishedValue = fn (): string => Status::Published->value;

it('checks a value through a helper of another file', function (): void {
    expect(draftStatusValueFromAnotherFile())->toBe('draft');
});

it('checks a value through a closure held in a variable', function () use ($publishedValue): void {
    expect($publishedValue())->toBe('published');
});
