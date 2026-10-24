<?php

declare(strict_types=1);

use Pest\Mutate\Plugins\Mutate;

it('takes longer than any time limit of the run', function (): void {
    if (getenv(Mutate::ENV_MUTATION_TESTING) !== false) {
        // the run of all the selected tests never finishes in time, and no test checks the value
        sleep(30);
    }

    expect(true)->toBeTrue();
});
