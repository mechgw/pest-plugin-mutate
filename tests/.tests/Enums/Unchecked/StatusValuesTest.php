<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Status;

it('reads the values without checking them', function (): void {
    expect(Status::Draft->value)->toBeString()
        ->and(Status::Published->value)->toBeString();
});
