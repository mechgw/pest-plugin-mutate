<?php

declare(strict_types=1);

use Pest\Mutate\Plugins\Mutate;
use Tests\Fixtures\Enums\Single;

it('checks the value', function (): void {
    if (getenv(Mutate::ENV_MUTATION_TESTING) !== false) {
        // the test run of a mutation never ends
        sleep(60);
    }

    expect(Single::Only->value)->toBe('only');
});
