<?php

declare(strict_types=1);

use Pest\Mutate\Plugins\Mutate;
use Tests\Fixtures\Enums\Single;

if (getenv(Mutate::ENV_MUTATION_TESTING) !== false) {
    // the test run of a mutation fails before running any test
    exit(2);
}

it('checks the value', function (): void {
    expect(Single::Only->value)->toBe('only');
});
