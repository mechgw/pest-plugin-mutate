<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Status;

// the enum is loaded before any test runs, so the test below executes no covered line
Status::cases();

it('checks the draft value', function (): void {
    expect(Status::Draft->value)->toBe('draft');
});
