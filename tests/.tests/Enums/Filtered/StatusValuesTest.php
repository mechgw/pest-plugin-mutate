<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Status;

it('checks the draft value', function (): void {
    expect(Status::Draft->value)->toBe('draft');
});

it('checks the published value', function (): void {
    expect(Status::Published->value)->toBe('published');
});
