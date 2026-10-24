<?php

declare(strict_types=1);

use Pest\Mutate\Plugins\Mutate;
use Tests\Fixtures\Enums\PublicationLabel;

it('checks a label returned by covered code', function (): void {
    if (getenv(Mutate::ENV_MUTATION_TESTING) !== false) {
        // the test run of a mutation of a covered line never ends
        sleep(30);
    }

    expect(PublicationLabel::unknown())->toBe('unknown');
});
