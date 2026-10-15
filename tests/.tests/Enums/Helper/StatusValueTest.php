<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Status;

// called by no test
function unusedPublishedStatusValue(): string
{
    return Status::Published->value;
}

it('checks the draft value', function (): void {
    expect(Status::Draft->value)->toBe('draft');
});
