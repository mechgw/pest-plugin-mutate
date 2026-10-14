<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Status;

it('checks one value', function (): void {
    expect(Status::Draft->value)->toBe('draft');
});
