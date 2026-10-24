<?php

declare(strict_types=1);

use Pest\Mutate\Plugins\Mutate;
use Tests\Fixtures\Enums\Single;

it('finds the case by its value after a while', function (): void {
    if (getenv(Mutate::ENV_MUTATION_TESTING) !== false) {
        // longer than the default time limit of the run, shorter than the configured one
        sleep(15);
    }

    // looked up by its value, the case is not referenced
    expect(Single::tryFrom('only'))->not->toBeNull();
});
