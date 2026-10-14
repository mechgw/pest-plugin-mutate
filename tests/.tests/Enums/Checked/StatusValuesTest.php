<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Status;

it('checks the values', function (): void {
    expect(Status::Draft->value)->toBe('draft')
        ->and(Status::Published->value)->toBe('published');
});
