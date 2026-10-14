<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Status;

it('reads a value without checking it', function (): void {
    expect(Status::Draft->value)->toBeString();
});
