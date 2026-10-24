<?php

declare(strict_types=1);

use Tests\Fixtures\Enums\Single;

it('reads the value without checking it', function (): void {
    expect(Single::Only->value)->toBeString();
});
